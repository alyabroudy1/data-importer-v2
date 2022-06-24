<?php

namespace App\Command;

use App\Controller\ImportAssistant;
use App\Controller\ImportController;
use App\Services\CSVImportService;
use App\Services\ImportService;
use Doctrine\ORM\EntityManagerInterface;
use phpDocumentor\Reflection\Types\This;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function PHPUnit\Framework\at;

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
     * @var SymfonyStyle $io to manage output style
     */
    private $io;
    /**
     * @var ImportService $importService service to import the data
     */
    private ImportService $importService;
    /**
     *  $repo MappingRepository Class
     */
    private $repo;
    /**
     * @var $fileObject \SplFileObject that represent the file to be imported
     */
    private $fileObject;


    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    /**
     * Configure the command
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addArgument('filePath', InputArgument::REQUIRED, 'Dateipfad')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description');
    }

    /**
     * @param InputInterface $input manage input
     * @param OutputInterface $output manage output
     * @return int Command state
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('filePath');

        //1. validate given filepath
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

        //2. initialize supported Importservice
        switch ($this->fileObject->getExtension()) {
            case 'csv':
                $this->importService = new CSVImportService($this->fileObject, $this->entityManager);
                return $this->importData();
            //break;
            case 'xls':
            case 'xlsx':
                break;
            default:
                return $this->handleError(self::ERROR_DATA_TYPE, [$this->fileObject->getExtension()]);
        }
        return Command::SUCCESS;
    }

    /**
     * start the process of importing the data
     * @return int Command status
     */
    public function importData()
    {

        $this->io->info('Sie haben eine [' . $this->fileObject->getExtension() . '] Datei-Pfad gegeben');
        $this->repo = $this->importService->getDataMappingRepository();

        //3. read data headers from the file
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

        //4. get and validate database headers
        $databaseValidationResult = $this->validateDatabaseHeaders($dataArray['tableName']);
        //5. get and validate file data headers
        $dataArray = $this->validateDataHeaders($dataArray);

        $databaseHeadersWithAttribute = [];
        $newAttributeInFile = [];
        $databaseHeaders = [];
        if ($databaseValidationResult['isExistingTable']) {
            $databaseHeaders = $databaseValidationResult['databaseHeaders'];
            $databaseHeadersWithAttribute = $databaseValidationResult['databaseHeadersWithAttribute'];

            $this->io->info('Data Table [' . $dataArray['tableName'] . ']' . '] mit folgende Attribute ist schon existiert:');
            $this->printTable($databaseHeadersWithAttribute, ['#', 'Field', 'Type']);

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
            $this->io->success('Table [' . $dataArray['tableName'] . '] mit folgende Attribute wurde erstellt.');
            $this->printTable($dataArray['headers'], ['id', 'name']);
           // dd($databaseValidationResult);
            $databaseValidationResult = $this->validateDatabaseHeaders($dataArray['tableName']);
            $databaseHeadersWithAttribute = $databaseValidationResult['databaseHeadersWithAttribute'];
            $databaseHeaders = $databaseValidationResult['databaseHeaders'];
           // dd('creating table');
           /* $databaseValidationResult = $this->validateDatabaseHeaders($dataArray['tableName']);
            $databaseHeadersWithAttribute = $databaseValidationResult['databaseHeadersWithAttribute'];
            $databaseHeaders = $databaseValidationResult['databaseHeaders'];
            $this->io->success('Table [' . $dataArray['tableName'] . '] mit folgende Attribute wurde erstellt.');
            $this->printTable($dataArray['headers'], ['id', 'name']);
           */
        }
        $this->persistDataToDatabase($dataArray, $databaseHeaders, $databaseHeadersWithAttribute, $newAttributeInFile);

        $this->io->success('Importieren der Daten aus Datei wurde abgeschloßen.');
        return Command::SUCCESS;
    }

    /**
     * @param $dataArray array contains file data-headers and data-rows
     * @param $databaseHeaders array database-headers
     * @param $databaseHeadersWithAttribute array database-headers with full information about attribute like type
     * @param $newAttributeInFile array new attribute to be added to file -data-headers if any.
     * @return int|void Command state in case of errors or warning
     */
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
            //$csvImportService->getDataMappingRepository()->truncate();

            $dataRows = $dataAdjustmentResult['dataRows'];
            $corruptedData = $dataAdjustmentResult['corruptedData'];
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
                "[" . $importedDataCount . "] imported Datensätze" . PHP_EOL .
                "[" . $duplicate . "] Doppelte Datensätze" . PHP_EOL .
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
     * @param $errorType string type of error
     * @param $options array if any options to add explaining the error
     * @return int|void Command state in case of errors or warning
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
     * handle read errors of data-headers
     * @param $errors array headers errors
     * @param $headers array data-headers
     * @return array corrected data headers and errors that couldn't be corrected if any.
     */
    private function handleHeaderReadErrors($errors, $headers)
    {
        $errorsCounter = 0;
        foreach ($errors as $err) {
            $duplicate = ', duplicate:' . $this->importService->arrayToString($err['duplicateRows']);
            if (!$err['duplicateRows']) {
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

    /**
     * handle duplication error of data-headers
     * @param $error array headers error
     * @param $headers array data-headers
     * @return array corrected data headers.
     */
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
     * proceed data importing assuming the database and tables already exist
     * @param $dataArray array data-headers and data-rows
     * @param $databaseHeaders array database-headers
     * @return array data-headers and data-rows and if there's new attribute to be added to data-headers in orders to match database
     */
    protected function proceedWithExistingTable($dataArray, $databaseHeaders): array
    {
       // $extraFieldsResult = $this->importService->detectNewFields($dataArray['headers'], $databaseHeaders);
        //$this->fixExtraFields($extraFieldsResult, $dataArray['headers'], $databaseHeaders);

        //dd('$extraFieldsResult:',$extraFieldsResult);
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
        }
        return ['dataArray' => $dataArray, 'newAttributeInFile' => $newAttributeInFile];
    }

    /**
     * @param $err array error of data-headers
     * @return array suggested solutionsList to correct the error
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

    /**
     * validate data-headers
     * @param array $dataArray data-headers
     * @return array|int Command state code if error or warning, or the validated headers
     */
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

        $test = [['id'=>33], ['id'=>33]];
        $this->printTable($test, ['Name'],['id']);
        //$this->printTable($dataArray['headers'], ['Name'],[]);
        $answer = $this->io->ask('Möchten Sie weiter machen? [y/n]', 'y');
        if ($answer == 'n') {
            return Command::INVALID;
        }
        return $dataArray;
    }

    /**
     * validate database-headers
     * @param mixed $tableName the name of database-table
     * @return array  'isExistingTable', 'databaseHeaders' and 'databaseHeadersWithAttribute'
     */
    private function validateDatabaseHeaders(mixed $tableName)
    {
        $isExistingTable = $this->repo->isTableExist($tableName);
        $databaseHeaders = [];
        $databaseHeadersWithAttribute = [];
        if ($isExistingTable) {
            $databaseHeaders = $this->repo->getExistingTableAttribute($tableName)['headers'];
            $databaseHeadersWithAttribute = $this->repo->getExistingTableAttribute($tableName)['headersWithAttribute'];
           // $this->io->info('Data Table [' . $tableName . ']' . '] mit folgende Attribute ist schon existiert:');
            //$this->printTable($databaseHeadersWithAttribute, ['#', 'Field', 'Type']);
        }
        return [
            'isExistingTable' => $isExistingTable,
            'databaseHeaders' => $databaseHeaders,
            'databaseHeadersWithAttribute' => $databaseHeadersWithAttribute
        ];
    }

    /**
     * fix comparison result errors
     * @param $dataHeaders array data-headers
     * @param $noMatchList array attributes that didn't match database-attributes
     * @param $databaseHeaders array database-headers
     * @return array 'dataHeaders', 'databaseHeaders' and 'newAttribute' if any.
     */
    private function fixHeadersCompareError($dataHeaders, $noMatchList, $databaseHeaders)
    {
        $this->io->warning("Folgende Data Attribute passt nicht mit Datenbank Attribute:");
        //dd($noMatchList);
        //$this->io->table(['Datenbank', 'File'], $noMatchList);
        //$this->io->text('Passt nicht mit Datenbank Attribute:');
        $this->printTable($noMatchList, ['Error', 'Attribute'], ['error', 'name']);
        dd('fixHeadersCompareError');
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

    /**
     * prints a table in the Console
     * @param $data array table headers
     * @param $cols array data to be filled in table columns
     * @return void
     */
    private function printTable($data, $cols = [], $attributes = [])
    {
       // $cols = array_merge(['#'],$cols);
       // dump($data);
        $table = $this->io->createTable()->setHeaders($cols);
        $table->addRows(array_column($data, $attributes[0]));
        dd(array_column($data, $attributes[0]));
        $newArray = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $colCounter = 0;
                $rowCol = [$key];
                foreach ($value as $key2 => $value2) {
                    if ($colCounter !== count($cols) - 1) {
                        $rowCol [] = $value2;
                        $colCounter++;
                    }
                }
                $newArray[$key] = $rowCol;
            } else
            {
                $newArray[$key] = [$key, trim($value)];
            }
        }
        $table = $this->io->createTable()->setHeaders($cols);
        $table->addRows($newArray);
        $table->render();
    }

    private function fixExtraFields(array $extraFieldsResult,mixed $headers, array $databaseHeaders)
    {
        $newAttribute = [];
        if ($extraFieldsResult['db']['attribute']){
            $this->io->warning("[Extra Attribute im Datenbank] Folgende Attribute fehlt im File:");
            $this->printTable($extraFieldsResult['db']['attribute'], ['#', 'Name']);

            foreach ($extraFieldsResult['db']['attribute'] as $att){
                $message = 'Möchten Sie die neue Attribute[' . $att . '] zum File addieren? [y/n]';
                $answer = $this->io->ask(
                    $message,
                    'y'
                );
                if ($answer !== 'y') {
                    //remove field from file
                }else{
                    //$databaseHeaders = $this->importService->addNewFieldToDatabase($att, $databaseHeaders);
                    $newAttribute [] = $att;
                    $this->io->success('Neue Attribut [' . $att . '] wurde zum File hinzugefügt.');
                }


            }

        }

    }
}