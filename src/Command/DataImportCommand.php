<?php

namespace App\Command;

use App\Controller\ImportAssistant;
use App\Controller\ImportController;
use App\Repository\DataMappingRepository_;
use App\Services\CSVImportService;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'data:import',
    description: 'Import Data from File',
    hidden: false
)]
class DataImportCommand extends \Symfony\Component\Console\Command\Command
{
    /**
     * @var array
     */
    const ACCEPT_FILE_EXTENSION = ['csv'];
    private $projectDir;

    public const ERROR_DATA_TYPE = 1;
    public const ERROR_READ = 2;
    public const ERROR_VERGLEICH = 3;
    public const ERROR_PERSIST = 4;

    public function __construct(EntityManagerInterface $entityManager, $projectDir)
    {
        $this->entityManager = $entityManager;
        $this->projectDir = $projectDir;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
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
        $io = new SymfonyStyle($input, $output);
        $arg1 = $input->getArgument('arg1');

        if ($arg1) {
            $io->note(sprintf('You passed an argument: %s', $arg1));
        }

        $io->writeln([
            'Data Importer wird gestartet.',
            '============================',
        ]);
        //$filePath ='src/import/Cars.csv';
        //$filePath ='src/import/MOCK_DATA.csv';
        //$filePath ='src/import/Cars_fehlerhafte.csv';
        $filePath = 'src/import/Cars_fehlerhafte_2.csv';

        //initialize importController and initialize importAssistant identifying data-file-type
        $importController = new ImportController();
        $dataImporter = $importController->identifyAndInitializeDataTypeImporter($filePath);

        $fileObject = new \SplFileObject($filePath);
        //return data-type Error if data type not supported
        if (null === $dataImporter) {
            $io->error('Leider den "' . $fileObject->getExtension() . '" Datei-typ ist momentan nicht unterstützt');
            return Command::FAILURE;
        }

        $io->info('Sie haben eine ' . $fileObject->getExtension() . ' Datei-Pfad gegeben');

        //read data from the file
        //TODO: identify corrupted data rows
        $dataRows = $dataImporter->readAndValidateData($filePath);

        $dataMappingRepository = new DataMappingRepository_(
            entityManager: $this->entityManager,
            table: str_replace('.' . $fileObject->getExtension(), '', trim($fileObject->getFilename())),
            io: $io
        );

        //identify data attribute
        $tableHeaders = $dataImporter->identifyDataAttribute($dataRows[0]);

        //if headers not in correct format return a readError
        if (!$tableHeaders) {
            $this->handleError(self::ERROR_READ, ["Fehler im Data-headers"], $io, $fileObject);
            return Command::INVALID;
        }

        //get objet/Table name
        $tableName = $dataImporter->getTableName($filePath);

        $io->info("[" . count($dataRows) . "]" . " Datensatz mit folgende Attribute wurde gefunden:");
        $io->text("Table:" . $tableName);
        $io->table($tableHeaders, []);
        //$io->table($tableHeaders, [$dataRows[0]]);

        $answer = $io->ask('Möchten Sie weiter machen? [y/n]', 'y');
        if ($answer == 'n') {
            return Command::INVALID;
        }

        if ($dataMappingRepository->isTableExist($tableName)) {
            $existingAttribute = $dataMappingRepository->getExistingTableAttribute($tableName);
            $io->info("Data Table [" . $tableName . "] mit folgende Attribute ist schon existiert:");
            $io->table($existingAttribute, []);

            $compareResult = $dataMappingRepository->compareNewDataToDatabase($tableHeaders, $existingAttribute);
            $matchList = $compareResult['match'];
            $noMatchList = $compareResult['noMatch'];
            if (count($noMatchList) === 0) {
                $io->success("Data Attribute passt mit Datenbank Attribute.");
            } else {
                $io->warning("Folgende Data Attribute passt nicht mit Datenbank Attribute:");
                $io->table($noMatchList, []);
                $io->text('Passt nicht mit Datenbank Attribute:');
                $io->table($existingAttribute, []);
                //todo: fix match error if possible
                return Command::INVALID;
            }

        } else {
            $answer = $io->ask('Data Table [' . $tableName . '] existiert nicht im Datenbank. Möchten Sie den Table [' . $tableName . '] erstellen? [y/n]', 'y');
            if ($answer == 'n') {
                return Command::INVALID;
            }
            //create table if not exist
            $dataMappingRepository->creatTable($tableHeaders);
        }

        $answer = $io->ask('Möchten Sie jetzt importieren? [y/n]', 'n');
        if ($answer == 'n') {
            return Command::INVALID;
        }
        //insert data
        $dataMappingRepository->insertNewDataToDatabase($tableHeaders, $dataRows);

        $io->success('Importieren der Daten aus Datei wurde abgeschloßen.');
        return Command::SUCCESS;
    }

    /**
     * /F050/ Status ermitteln
     * @return void
     */
    private function showStatus(SymfonyStyle $io, $message, $options)
    {
    }

    /**
     * /F060/ Fehler behandeln
     */
    private function handleError($errorType, $options, SymfonyStyle $io, \SplFileObject $fileObject)
    {
        /*ERROR_DATA_TYPE = 1;
           public const ERROR_READ = 2;
           public const ERROR_VERGLEICH = 3;
           public const ERROR_PERSIST = 4;
               */
        $message = '';
        switch ($errorType) {
            case self::ERROR_DATA_TYPE:
                $message = 'Data-type Fehler: [' . $fileObject->getExtension() . '] Datei-typ ist momentan nicht unterstützt';
                break;
            case self::ERROR_READ:
                $message = 'Lese Fehler: [' . $options[0] . ']';
                break;
            case self::ERROR_VERGLEICH:
                $message = 'Lese Fehler: [' . $options[0] . ']';
                break;

        }


    }

}