<?php

namespace App\Services;

use App\Repository\DataMappingRepository;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use SplFileObject;

class CSVImportService extends ImportService
{
    /**
     * @var string
     */
    public const IMPORT_FILE_DELIMITER = ',';

    /**
     * @var SplFileObject
     */
    protected SplFileObject $fileObject;

    /**
     * @param SplFileObject $fileObject
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(SplFileObject $fileObject, EntityManagerInterface $entityManager)
    {
        $fileObject->setCsvControl(self::IMPORT_FILE_DELIMITER);
        $fileObject->setFlags(SplFileObject::READ_CSV);
        $this->fileObject = $fileObject;
        $this->dataMappingRepository = new DataMappingRepository(
            entityManager: $entityManager,
            table: str_replace('.' . $fileObject->getExtension(), '', trim($fileObject->getFilename()))
        );
    }

    /**
     * get tableName, tableHeaders, und dataRows from csv file
     * @return array
     * @throws Exception
     */
    public function readDataHeaders()
    {
        //$this->exportCSVToFile();
        /* $serializer = new Serializer([new ObjectNormalizer()], [new CsvEncoder()]);
         $dataRows = $serializer->decode(file_get_contents($filePath), 'csv');
        */
        $tableName = str_replace(
            '.' . $this->fileObject->getExtension(),
            '',
            $this->fileObject->getBasename()
        );
        $headers = [];
        $firstRowData = [];

        foreach ($this->fileObject as $i => $row) {
            if ($i == 0) {
                $headers = $row;
            }elseif ($i == 1){
                $firstRowData = $row;
                break;
            }
        }
        $headersType = parent::detectDataType($headers, $firstRowData);
       // dd('headersWithType');
        return [
            'tableName' => $tableName,
            'headers' => $headers,
            'headersType' => $headersType
        ];
    }

    public function readData($headers, $newAttribute)
    {
        $dataRows = [];
        foreach ($this->fileObject as $i => $row) {
            if ($i == 0) {
                continue;
            } else {
                $dataRows[$i] = $row;
            }
        }
        return $dataRows;
    }

    /**
     * @return DataMappingRepository
     */
    public function getDataMappingRepository(): DataMappingRepository
    {
        return $this->dataMappingRepository;
    }

}