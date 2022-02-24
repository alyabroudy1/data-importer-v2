<?php

namespace App\Controller;

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
            'Product1' => [
                ['id' => 111, "name" => 'ps5_1'],
                ['Price' => 501],
            ],
            'Product2' => [
                ['id' => 112, "name" => 'ps5_2'],
                ['Price' => 502],
            ],
            'Product3' => [
                ['id' => 113, "name" => 'ps5_3'],
                ['Price' => 503],
            ]
        ];
// encoding contents in CSV format
        file_put_contents(
            'data.csv',
            $serializer->encode($data, 'csv')
        );
    }

    function readFile(string $filePath): string
    {
        //$this->exportCSVToFile();
        $serializer = new Serializer([new ObjectNormalizer()], [new CsvEncoder()]);
        // TODO: Implement readFile() method.
// decoding CSV contents
        $data2 = $serializer->decode(file_get_contents('data.csv'), 'csv');

        //return $filePath."from readFile CSVImportAssistant";
        var_dump($data2);
        return $data2;
    }



}
