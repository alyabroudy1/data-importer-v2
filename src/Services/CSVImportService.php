<?php

namespace App\Services;

use App\Repository\DataMappingRepository;
use Doctrine\ORM\EntityManagerInterface;

class CSVImportService
{
    /**
     * @var string
     */
    const IMPORT_FILE_DELIMITER = ';';

    /**
     * @var \SplFileObject
     */
    protected \SplFileObject $fileObject;

    /**
     * @var DataMappingRepository $dataMappingRepository
     */
    protected $dataMappingRepository;

    /**
     * @param \SplFileObject $fileObject
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(\SplFileObject $fileObject, EntityManagerInterface $entityManager)
    {
        $fileObject->setCsvControl(self::IMPORT_FILE_DELIMITER);
        $fileObject->setFlags(\SplFileObject::READ_CSV);
        $this->fileObject = $fileObject;
        $this->dataMappingRepository = new DataMappingRepository(
            entityManager: $entityManager,
            table: str_replace('.' . $fileObject->getExtension(), '', strtolower(trim($fileObject->getFilename()))));
    }

    /**
     * @return array
     * @throws \Doctrine\DBAL\Exception
     */
    public function run(){
        $csvArray = [];
        foreach ($this->fileObject as $i => $row) {
            if($i == 0) {
                $this->dataMappingRepository->creatTable($row);
            } else {
                foreach ($row as $col => $value) {
                    /* TODO $this->validate($value) Implementierung*/
                    //$validateArray [$i] [$this->dataMappingRepository->getFields()[$col]] = 'In dem Row haben Sie Das Feld nicht Korrekt ausgefüllt';
                    $csvArray [$i] [$this->dataMappingRepository->getFields()[$col + 1]] = json_encode(trim($value));
                }
            }
        }
        // Validate Array ins Command mit csv übergeben
        return ['csv' => $csvArray, 'validateResult' => []];
    }

    /**
     * @return void
     */
    protected function validate($value){
        /* TODO */
    }

    /**
     * @return DataMappingRepository
     */
    public function getDataMappingRepository(): DataMappingRepository
    {
        return $this->dataMappingRepository;
    }
}