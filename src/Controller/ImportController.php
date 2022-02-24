<?php

namespace App\Controller;

use JetBrains\PhpStorm\Pure;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ImportController extends AbstractController
{


    #[Pure] public function initializeImporter($filePath): ImportAssistant
    {
        $dataImporter = null;
        $csv_cond = str_contains($filePath, ImportAssistant::CSV_FILE_TYPE);

        if ($csv_cond){
            $dataImporter = new CSVImportAssistant();
        }
        return $dataImporter;
    }
}
