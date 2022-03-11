<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

abstract class ImportAssistant extends AbstractController
{
    public const CSV_FILE_TYPE = "csv";

    protected string $fileType;

    /**
     * /F020/ Daten Auslesen und Validieren
     */
    abstract function readAndValidateData(string $filePath);

    /**
     * /F030/ Daten Spalten Identifizieren
     */
    abstract function identifyDataAttribute($dataRow);

    abstract function getTableName(string $filePath);



    /**
     * @return string
     */
    public function getFileType(): string
    {
        return $this->fileType;
    }


}
