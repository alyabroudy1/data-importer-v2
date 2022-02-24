<?php

namespace App\Command;

use App\Controller\ImportAssistant;
use App\Controller\ImportController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'data:import',
    description: 'Add a short description for your command',
)]
class DataImportCommand extends Command
{
    private ImportController $importController;

    public function __construct(ImportController $importController)
    {
        parent::__construct(null);
        $this->importController = $importController;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $arg1 = $input->getArgument('arg1');
        $importAssistant = null;

        if ($arg1) {
            /** @var $arg1 string */
            $io->note(sprintf('You passed an argument: %s', $arg1));

                $importAssistant = $this->importController->initializeImporter($arg1);
                $io->note(sprintf('You passed a '.$importAssistant->getFILETYPE().' File : %s', $importAssistant->getName()));
                $importAssistant->readFile($arg1);


        }

        if ($input->getOption('option1')) {
            // ...
        }

        $io->success('You have a new command! Now make it your own! Pass --help to see your options.');

        return Command::SUCCESS;
    }
}
