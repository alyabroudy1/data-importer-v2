<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

abstract class ImportAssistant  extends AbstractController
{
    public const CSV_IMPORT_ASSISTANT = "CSVImportAssistant";
    public const CSV_FILE_TYPE = "csv";

    /** @var $Name string name of the import-assistant */
    protected string $Name;
    protected string $FILE_TYPE;

    abstract function readFile(string $filePath);

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->Name;
    }

    /**
     * @return string
     */
    public function getFILETYPE(): string
    {
        return $this->FILE_TYPE;
    }



}
