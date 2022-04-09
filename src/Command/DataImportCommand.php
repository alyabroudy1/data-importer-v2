<?php

namespace App\Command;

use App\Controller\ImportAssistant;
use App\Controller\ImportController;
use App\Repository\DataMappingRepository_;
use App\Services\CSVImportService;
use App\Services\ImportService;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use mysql_xdevapi\Result;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function PHPUnit\Framework\returnArgument;

#[AsCommand(
    name: 'data:import',
    description: 'Import Data from File',
    hidden: false
)]
class DataImportCommand extends \Symfony\Component\Console\Command\Command
{

    public const ERROR_DATA_TYPE = 1;
    public const ERROR_READ = 2;
    public const ERROR_VERGLEICH = 3;
    public const ERROR_PERSIST = 4;

    /**
     * @var SymfonyStyle
     */
    private $io;
    private ImportService $importService;
    private $repo;
    private $fileObject;


    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('filePath', InputArgument::REQUIRED, 'Dateipfad')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('filePath');

      // dd( $this->validateDate('-20191109' ) );
        //dd('done');

        //validate given filepath
        if (!$filePath) {
            return $this->handleError(
                self::ERROR_READ,
                ['Sie haben kein file-pfad gegeben. File-pfad ist erforderlich.']
            );
        }
        if (!is_file($filePath)) {
            return $this->handleError(self::ERROR_READ, ['File-pfad [' . $filePath . '] wurde nicht gefunden']);
        }
        $this->io->writeln([
            'Data Importer wird gestartet.',
            '============================',
        ]);

        $this->fileObject = new \SplFileObject($filePath);

        switch ($this->fileObject->getExtension()) {
            case 'csv':
                $this->importService = new CSVImportService($this->fileObject, $this->entityManager);
                return $this->importData();
            //break;
            case 'xls':
            case 'xlsx':
                /* @TODO Importer erweitern */
                break;
            default:
                //$io->error('Die Dateiformat ['.$this->fileObject->getExtension().'] wurde von System nicht akzeptiert.');
                //return Command::FAILURE;
                return $this->handleError(self::ERROR_DATA_TYPE, [$this->fileObject->getExtension()]);
        }
        return Command::SUCCESS;
    }

    /**
     * @return int Command status
     */
    public function importData()
    {
        $this->io->info('Sie haben eine [' . $this->fileObject->getExtension() . '] Datei-Pfad gegeben');
        $this->repo = $this->importService->getDataMappingRepository();

        $dataArray = $this->importService->readDataHeaders();
        $headersType = $dataArray['headersType'];

        if (!$dataArray['headers']) {
            $this->handleError(self::ERROR_READ, ["fehlerhafte Daten Header Fehler"]);
            return Command::INVALID;
        }

        $databaseName = $_ENV["DB_NAME"];
        if (!$databaseName) {
            $this->handleError(self::ERROR_READ, ["Fehler im Datenbank Adresse"]);
            return Command::INVALID;
        }
        $databaseValidationResult = $this->validateDatabaseHeaders($dataArray['tableName']);
        $dataArray = $this->validateDataHeaders($dataArray);

        $databaseHeadersWithAttribute = [];
        $newAttributeInFile = [];
        $databaseHeaders = [];
        if ($databaseValidationResult['isExistingTable']) {
            $databaseHeaders = $databaseValidationResult['databaseHeaders'];
            $databaseHeadersWithAttribute = $databaseValidationResult['databaseHeadersWithAttribute'];

            $processWithExistingTableResult = $this->proceedWithExistingTable($dataArray, $databaseHeaders);
            $newAttributeInFile = $processWithExistingTableResult['newAttributeInFile'];
            $dataArray = $processWithExistingTableResult['dataArray'];
        } else {
            $answer = $this->io->ask(
                'Data Table [' . $dataArray['tableName'] . '] existiert nicht im Datenbank. Möchten Sie den Table [' . $dataArray['tableName'] . '] erstellen? [y/n]',
                'y'
            );
            if ($answer == 'n') {
                return Command::INVALID;
            }
            //create table if not exist
            $this->repo->creatTable($dataArray['headers'], $headersType);
            $databaseValidationResult = $this->validateDatabaseHeaders($dataArray['tableName']);
            $databaseHeadersWithAttribute = $databaseValidationResult['databaseHeadersWithAttribute'];
            $databaseHeaders = $databaseValidationResult['databaseHeaders'];
            $this->io->success('Table [' . $dataArray['tableName'] . '] mit folgende Attribute wurde erstellt.');
            $this->printTable($dataArray['headers'], ['id', 'name']);
            //$this->io->table($dataArray['headers'], []);
        }

        //TODO: check if database exist
        /* if (!$repo->isExistDatabase($databaseName)) {
             $answer = $io->ask('Datenbank ['.$databaseName.']existiert nicht. Möchten Sie den Datenbank ['.$databaseName.'] erstellen? [y/n]', 'y');
             if ($answer == 'n') {
                 return Command::INVALID;
             }
             $repo->createDatabase($databaseName);
             $io->success('Datenbank ['.$databaseName.'] wurde erstellt.');
         }*/

        $this->persistDataToDatabase($dataArray, $databaseHeaders, $databaseHeadersWithAttribute, $newAttributeInFile);

        $this->io->success('Importieren der Daten aus Datei wurde abgeschloßen.');
        return Command::SUCCESS;
    }

    private function persistDataToDatabase(
        $dataArray,
        $databaseHeaders,
        $databaseHeadersWithAttribute,
        $newAttributeInFile
    ) {
        $answer = $this->io->ask('Möchten Sie jetzt importieren? [y/n]', 'n');
        if ($answer == 'y') {
            $dataRows = $this->importService->readData($databaseHeadersWithAttribute, $newAttributeInFile);

            $dataAdjustmentResult = $this->importService->adjustData(
                $dataRows,
                $databaseHeadersWithAttribute,
                $newAttributeInFile
            );

            $dataRows = $dataAdjustmentResult['dataRows'];
            $corruptedData = $dataAdjustmentResult['corruptedData'];
            // $this->importService->adjustDataHeaderKeys($databaseHeaders, $newAttributeInFile);
            //$csvImportService->getDataMappingRepository()->truncate();
            $this->io->progressStart(count($dataRows));
            $duplicate = 0;
            $importedDataCount = 0;
            //insert data
            foreach ($dataRows as $row) {
                $this->io->progressAdvance();
                if ($this->repo->insertNewDataToDatabase($databaseHeaders, $row)) {
                    $importedDataCount++;
                } else {
                    $duplicate++;
                }
            }
            $this->io->progressFinish();
            if (count($corruptedData) > 0) {
                $this->io->text("Fehlerhafte Datensätze:");
                $this->printTable($corruptedData, ['#', 'Fehler', 'Zeile']);
            }
            $this->io->success(
                "[" . $importedDataCount . "] imported Datensätze" . PHP_EOL.
                "[" . $duplicate . "] Doppelte Datensätze" . PHP_EOL.
                "[" . count($corruptedData) . "] Fehlerhafte Datensätze"
            );
        } elseif ($answer == 'n') {
            $this->io->info('Import Process wurde abgebrochen');
            return Command::SUCCESS;
        } else {
            $this->io->error('Die Option wurde nicht gefunden!');
            return Command::FAILURE;
        }
    }

    /**
     * /F060/ Fehler behandeln
     */
    private function handleError($errorType, $options = [""])
    {
        $message = '';
        switch ($errorType) {
            case self::ERROR_DATA_TYPE:
                $message = 'Data-type Fehler: ' . $options[0] . ' Datei-typ ist momentan nicht unterstützt';
                $this->io->error($message);
                return Command::INVALID;
            case self::ERROR_READ:
                $message = 'Lese Fehler: [' . $options[0] . ']';
                $this->io->error($message);
                return Command::INVALID;
            case self::ERROR_VERGLEICH:
                $message = 'Vergleich Fehler: [' . $options[0] . ']';
                $this->io->warning($message);
                break;
            case self::ERROR_PERSIST:
                $message = 'Persist Fehler: [' . $options[0] . ']';
                $this->io->error($message);
                return Command::INVALID;
            default:
                $message = 'Fehler: [' . $options[0] . ']';
                $this->io->error($message);
                return Command::INVALID;
        }
    }

    /**
     * @param $errors
     * @param $headers
     * @return array
     */
    private function handleHeaderReadErrors($errors, $headers)
    {
        $errorsCounter = 0;
        foreach ($errors as $err) {
            $duplicate =', duplicate:' . $this->importService->arrayToString($err['duplicateRows']);
            if (!$err['duplicateRows']){
                $duplicate = '';
            }
            $row = '[' . $err['error'] . '] in Zeile:' . $this->importService->arrayToString($err['row']) .
                $duplicate;

            $this->io->error($row);
            //correct error
            $headers = $this->handleHeaderDuplicationError($err, $headers);
            unset($errors[$errorsCounter]);
            $errorsCounter++;
        }
        return ['headers' => $headers, 'errors' => $errors];
    }

    private function handleHeaderDuplicationError($error, $headers)
    {
        $solutionList = $this->suggestSolutions($error);
        foreach ($solutionList as $sol) {
            $this->io->block('Attribut: ' . $this->importService->arrayToString($sol['error']));
            $choice = $this->io->choice('Wie möchten Sie den Attribut umbenennen?', $sol['solutions']);
            $headers[array_key_first($sol['error'])] = $choice;
            $this->io->success(
                $this->importService->arrayToString($sol['error']) . ' wurde [' . $choice . '] umbenennt.'
            );
        }
        return $headers;
    }

    /**
     * @param $dataArray
     * @param $databaseHeaders
     * @return array
     */
    protected function proceedWithExistingTable($dataArray, $databaseHeaders): array
    {
        $compareResult = $this->importService->compareNewDataToDatabase($dataArray['headers'], $databaseHeaders);
        $matchList = $compareResult['match'];
        $noMatchList = $compareResult['noMatch'];
        $newAttributeInFile = [];
        if (count($noMatchList) === 0) {
            $this->io->success("Data Attribute passt mit Datenbank Attribute.");
        } else {
            $compareFixResult = $this->fixHeadersCompareError($dataArray['headers'], $noMatchList, $databaseHeaders);
            $dataArray['headers'] = $compareFixResult ['dataHeaders'];
            $newAttributeInFile = $compareFixResult['newAttribute'];
            //$dataArray= $this->fixCompareError($dataArray, $noMatchList, $databaseHeaders);
        }
        return ['dataArray' => $dataArray, 'newAttributeInFile' => $newAttributeInFile];
    }

    /**
     * @param $err
     * @return array
     */
    private function suggestSolutions($err)
    {
        $fEn = new \NumberFormatter("en", \NumberFormatter::SPELLOUT);
        $fDe = new \NumberFormatter("de", \NumberFormatter::SPELLOUT);
        $solutionsList = [];
        if ($err['error'] === 'duplicated Attribute') {
            $firstOccurrence = $this->importService->arrayToStringValue($err['row']);
            $counter = 1;
            $solutionsList [] = [
                'error' => $err['row'],
                'solutions' => [
                    '1' => $firstOccurrence . '_' . $counter,
                    '2' => $firstOccurrence . '_' . $fEn->format($counter),
                    '3' => $firstOccurrence . '_' . $fDe->format($counter++),
                    '4' => $firstOccurrence . '_' . array_key_first($err['row']),
                    '5' => $firstOccurrence
                ]
            ];
            foreach ($err['duplicateRows'] as $duplicate) {
                $duplicateValue = $this->importService->arrayToStringValue($duplicate);
                $solutionsList [] = [
                    'error' => $duplicate,
                    'solutions' => [
                        '1' => $duplicateValue . '_' . $counter,
                        '2' => $duplicateValue . '_' . $fEn->format($counter),
                        '3' => $duplicateValue . '_' . $fDe->format($counter++),
                        '4' => $duplicateValue . '_' . array_key_first($duplicate)
                        //'5' => $duplicateValue
                    ]
                ];
            }
        } elseif ($err['error'] === 'Attribute Name Error') {
            $counter = 1;
            foreach ($err['row'] as $nameErr) {
                $nameErrValue = preg_replace(
                    '/[\'^£$%&*()}{@#~?><>\/,.:|=+¬]/',
                    '',
                    trim($this->importService->arrayToStringValue($nameErr))
                );
                if ($nameErrValue === '') {
                    $nameErrValue = 'field';
                } elseif (is_numeric($nameErrValue)) {
                    $nameErrValue = 'field_' . $nameErrValue;
                }
                $solutionsList [] = [
                    'error' => $nameErr,
                    'solutions' => [
                        '1' => $nameErrValue . '_' . $counter,
                        '2' => $nameErrValue . '_' . $fEn->format($counter),
                        '3' => $nameErrValue . '_' . $fDe->format($counter++),
                        '4' => $nameErrValue . '_' . array_key_first($nameErr),
                        '5' => $nameErrValue
                    ]
                ];
            }
        }
        return $solutionsList;
    }

    private function validateDataHeaders(array $dataArray)
    {
        $headerErrors = $this->importService->isValidHeader($dataArray['headers']);
        if (!$dataArray['headers']) {
            $this->handleError(self::ERROR_READ, ["Fehler im Data Headers"]);
            return Command::INVALID;
        }
        while (count($headerErrors) > 0) {
            $handleResult = $this->handleHeaderReadErrors($headerErrors, $dataArray['headers']);
            $dataArray['headers'] = $handleResult['headers'];
            $headerErrors = $this->importService->isValidHeader($dataArray['headers']);
        }
        $this->io->info("Datensatz mit folgende Attribute wurde gefunden:");
        $this->io->text("Table:" . $dataArray['tableName']);
        $this->printTable($dataArray['headers'], ['#', 'Name']);
        $answer = $this->io->ask('Möchten Sie weiter machen? [y/n]', 'y');
        if ($answer == 'n') {
            return Command::INVALID;
        }
        return $dataArray;
    }

    private function validateDatabaseHeaders(mixed $tableName)
    {
        $isExistingTable = $this->repo->isTableExist($tableName);
        $databaseHeaders = [];
        $databaseHeadersWithAttribute = [];
        if ($isExistingTable) {
            $databaseHeaders = $this->repo->getExistingTableAttribute($tableName)['headers'];
            $databaseHeadersWithAttribute = $this->repo->getExistingTableAttribute($tableName)['headersWithAttribute'];
            $this->io->info('Data Table [' . $tableName . ']' . '] mit folgende Attribute ist schon existiert:');
            $this->printTable($databaseHeadersWithAttribute, ['#', 'Field', 'Type']);
        }
        return [
            'isExistingTable' => $isExistingTable,
            'databaseHeaders' => $databaseHeaders,
            'databaseHeadersWithAttribute' => $databaseHeadersWithAttribute
        ];
    }

    private function fixHeadersCompareError($dataHeaders, $noMatchList, $databaseHeaders)
    {
        $this->io->warning("Folgende Data Attribute passt nicht mit Datenbank Attribute:");
        $this->io->table(['Datenbank', 'File'], $noMatchList);
        $this->io->text('Passt nicht mit Datenbank Attribute:');
        $this->printTable($databaseHeaders, ['#', 'Name']);
        $newAttribute = [];
        foreach ($noMatchList as $row) {
            $newAttribute = [];
            $databaseAttribute = $row[0];
            $fileAttribute = $row[1];
            $targetName = $databaseAttribute;
            if ($targetName === '[leer]') {
                $targetName = $fileAttribute;
                $this->io->warning('Field [' . $targetName . '] existiert nicht im Datenbank!');
                $answer = $this->io->ask(
                    'Möchten Sie den Field [' . $targetName . '] zur Datenbank addieren? [y/n]',
                    'y'
                );
                if ($answer == 'y') {
                    $databaseHeaders = $this->importService->addNewFieldToDatabase($targetName, $databaseHeaders);
                }
            } else {
                $message = 'Möchten Sie [' . $fileAttribute . '] im File zu [' . $targetName . '] umbenennen und importieren()? [y/n]';
                if ($fileAttribute === '[leer]') {
                    $message = 'Möchten Sie neue Attribut[' . $targetName . '] im File hinzufügen und die zu importierende Daten anpassen? [y/n]';
                }
                $answer = $this->io->ask(
                    $message,
                    'y'
                );
                if ($answer == 'y') {
                    $isNewAttribute = $fileAttribute === '[leer]';

                    foreach ($dataHeaders as $col => $value) {
                        if ($value === $fileAttribute || empty($fileAttribute)) {
                            $dataHeaders[$col] = $targetName;
                            $this->io->success('[' . $fileAttribute . '] wurde in [' . $targetName . '] umbenennt.');
                        }
                    }
                    if ($isNewAttribute) {
                        $newAttribute [] = $targetName;
                        $this->io->success('Neue Attribut [' . $targetName . '] wurde zum File hinzugefügt.');
                    }
                }
            }
        }
        return [
            'dataHeaders' => $databaseHeaders,
            'databaseHeaders' => $databaseHeaders,
            'newAttribute' => $newAttribute
        ];
    }

    private function printTable($databaseHeaders, $cols = [])
    {
        $newArray = [];
        foreach ($databaseHeaders as $arrayKey => $array) {
            if (is_array($array)) {
                $colCounter = 0;
                $rowCol = [$arrayKey];
                foreach ($array as $row => $value) {
                    if ($colCounter !== count($cols) - 1) {
                        $rowCol [] = $value;
                        $colCounter++;
                    }
                }
                $newArray[$arrayKey] = $rowCol;
            } else {
                $newArray[$arrayKey] = [$arrayKey, $array];
            }
        }
        $table = $this->io->createTable()->setHeaders($cols);
        $table->addRows($newArray);
        $table->render();
    }
}