<?php

namespace App\Command;

use App\Controller\ImportController;
use App\Repository\DataMappingRepository;
use App\Repository\DataMappingRepository_v2;
use App\Services\CSVImportService;
use Doctrine\ORM\EntityManagerInterface;
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
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
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
        $filePath ='src/import/MOCK_DATA.csv';

        $importController = new ImportController();
        $dataImporter = $importController->initializeImporter($filePath);

        if (null === $dataImporter){
            $io->error('Die Dateiformat wurde von System nicht akzeptiert.');
            return Command::FAILURE;
        }

        $dataRows = $dataImporter->readFile($filePath);


        $fileObject = new \SplFileObject($filePath);
        $dataMappingRepository = new DataMappingRepository_v2(
            entityManager: $this->entityManager,
            table: str_replace('.' . $fileObject->getExtension(), '', trim($fileObject->getFilename())),
            io: $io
        );

        $tableHeaders = $dataImporter->getTableHeader($dataRows[0]);
        $tableName = $dataImporter->getTableName($filePath);

        $io->info("[".count($dataRows)."]"." Datensatz mit folgende Attribute wurde gefunden:");
        $io->text("Table:".$tableName);
        $io->table($tableHeaders, []);
        //$io->table($tableHeaders, [$dataRows[0]]);

        $answer = $io->ask('Möchten Sie weiter machen? [y/n]','y');
        if ($answer == 'n') {
            return Command::INVALID;
        }

        if ($dataMappingRepository->isExistingTable($tableName)){
            $existingAttribute = $dataMappingRepository->getExistingTableAttribute($tableName);
            $io->info("Data Table [".$tableName."] mit folgende Attribute ist schon existiert:");
            $io->table($existingAttribute, []);

            $compareResult = $dataMappingRepository->compareToDatabase($tableHeaders, $existingAttribute);
            $matchList = $compareResult['match'];
            $noMatchList =$compareResult['noMatch'];
            if (count($noMatchList) === 0){
                $io->success("Data Attribute passt mit Datenbank Attribute.");
            }else{
                $io->warning("Folgende Data Attribute passt nicht mit Datenbank Attribute:");
                $io->table($noMatchList, []);
                $io->text('Passt nicht mit Datenbank Attribute:');
                $io->table($existingAttribute, []);
                //todo: fix match error if possible
                return Command::INVALID;
            }

        }else{
            $answer = $io->ask('Data Table ['.$tableName.'] existiert nicht im Datenbank. Möchten Sie den Table ['.$tableName.'] erstellen? [y/n]','y');
            if ($answer == 'n') {
                return Command::INVALID;
            }
            //create table if not exist
            $dataMappingRepository->creatTable($tableHeaders);
        }

        $answer = $io->ask('Möchten Sie jetzt importieren? [y/n]','n');
        if ($answer == 'n') {
            return Command::INVALID;
        }
        //insert data
        $dataMappingRepository->insert($tableHeaders,$dataRows);

        $io->success('Importieren der Daten aus Datei wurde abgeschloßen.');
        return Command::SUCCESS;
    }
}