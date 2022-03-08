<?php

namespace App\Controller;

use App\Repository\DataMappingRepository_v2;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class CSVImportAssistant extends ImportAssistant
{
        protected string $Name = ImportAssistant::CSV_IMPORT_ASSISTANT;
        protected string $FILE_TYPE = "csv";

    //function exportToFile(string $filePath, $data): string
    function exportCSVToFile()
    {
        $serializer = new Serializer([new ObjectNormalizer()], [new CsvEncoder()]);
        $data = [
            [
                'id' => 111, "name" => 'ps5_1', 'Price' => 501,
            ],
            [
                'id' => 112, "name" => 'ps5_2', 'Price' => 502,
            ],
             [
                 'id' => 113, "name" => 'ps5_3', 'Price' => 503,
            ]
        ];
// encoding contents in CSV format
        file_put_contents(
            'src/import/data.csv',
            $serializer->encode($data, 'csv')
        );
    }

    function readFile(string $filePath)
    {
        //$this->exportCSVToFile();
        $serializer = new Serializer([new ObjectNormalizer()], [new CsvEncoder()]);
        // TODO: Implement readFile() method.
// decoding CSV contents
        $dataRows = $serializer->decode(file_get_contents($filePath), 'csv');
        //return $filePath."from readFile CSVImportAssistant";
      //  var_dump($data2);

        //todo: check duplicate
        //$this->checkDeduplicate($dataRows);
        return $dataRows;
    }

    public function getTableName($filePath)
    {
        $fileObject = new \SplFileObject($filePath);
       return str_replace('.'.$fileObject->getExtension(),'', $fileObject->getBasename()) ;
    }

    public function checkDeduplicate($dataRow)
    {
    }

    public function getTableHeader($dataRow)
    {
        $headers = [];
        //dump($dataRow);
        foreach ($dataRow as $col => $value){
      //  dump($col);
            $headers []= $col;
        }
        return $headers;
    }



}
