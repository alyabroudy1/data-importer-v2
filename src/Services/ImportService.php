<?php

namespace App\Services;

use App\Repository\DataMappingRepository;

abstract class ImportService
{

    /**
     * @var DataMappingRepository $dataMappingRepository
     */
    protected $dataMappingRepository;

    abstract public function readDataHeaders();

    abstract public function readData($headers, $newAttribute);

    abstract public function getDataMappingRepository();

    /**
     * /F040/ Daten Spalten mit Datenbank vergleichen und Mappen
     */
    public function compareNewDataToDatabase($newAttribute, $databaseHeaders)
    {
        $noMatchList = [];
        $matchList = [];
        $anzahlDatabaseHeaders = count($databaseHeaders);
        $anzahlNewHeaders = count($newAttribute);

        $anzahlLoop = count($databaseHeaders);
        $counter = max($anzahlNewHeaders, $anzahlDatabaseHeaders);

        if ($anzahlNewHeaders < $anzahlDatabaseHeaders) {
            $anzahlLoop = $anzahlNewHeaders;
        }

        //dump(count($newAttribute), count($databaseHeaders), $anzahlLoop);
        for ($i = 0; $i < $counter; $i++) {
            if (empty($databaseHeaders[$i])) {
                //dump( '[leer]', $newAttribute[$i]);
                //$noMatchList [] = array('[leer]', $i => $newAttribute[$i]);
                $noMatchList [] = array('[leer]', $newAttribute[$i]);
            } elseif (empty($newAttribute[$i])) {
                //dump( $databaseHeaders[$i], '[leer]');
                //$noMatchList [] = [$i => $databaseHeaders[$i], '[leer]' ];
                $noMatchList [] = [$databaseHeaders[$i], '[leer]'];
            } elseif ($databaseHeaders[$i] === $newAttribute[$i]) {
                $matchList [] = $databaseHeaders[$i];
            } else {
                $noMatchList [] = [$databaseHeaders[$i], $newAttribute[$i]];
                //$noMatchList [] = [$i => $databaseHeaders[$i], $newAttribute[$i] ];
            }
        }
        return ['match' => $matchList, 'noMatch' => $noMatchList];
    }

    public function addNewFieldToDatabase($newFieldName, $databaseHeaders)
    {
        $this->dataMappingRepository->addNewFieldToDatabase($newFieldName);
        $databaseHeaders[] = $newFieldName;
        return $databaseHeaders;
    }

    public function isValidHeader($headers)
    {
        /*
         * 1.no headers => error
         * 2.name error => empty name, strange name like numbers only
         * 3. duplicated field
         */
        $errorList = [];
        if (!empty($headers)) {
            $error = "Attribute Name Error";
            $rows = [];
            foreach ($headers as $attribute => $value) {
                //$value = $value['Field'];
                $strangeNameCond = $value === '' || is_numeric($value) || strlen($value) < 2;
                $strangeNameCond2 = strlen($value) > 75 || preg_match('/[\'^£$%&*()}{@#~?><>\/,.:|=+¬]/', $value);
                if ($strangeNameCond || $strangeNameCond2) {
                    if (strlen($value) > 30) {
                        $value = substr($value, 0, 20);
                        //dump($value);
                    }
                    $rows[] = [$attribute => $value];
                }
            }
            if ($rows) {
                $errorList [] = ['error' => $error, 'row' => $rows, 'duplicateRows' => []];
            }
        }
        $headersCopy = $headers;

        $error = "duplicated Attribute";
        foreach ($headersCopy as $att => $attValue) {
            $duplicateCounter = 0;
            $firstErrorRow = [$att => $attValue];
            $duplicateRows = [];
            foreach ($headersCopy as $att2 => $att2Value) {
                if ($attValue === $att2Value) {
                    if ($duplicateCounter > 0) {
                        $duplicateRows[] = [$att2 => $att2Value];
                        unset($headersCopy[$att2]);
                    }
                    $duplicateCounter++;
                }
            }
            if (count($duplicateRows) > 0) {
                $errorList [] = ['error' => $error, 'row' => $firstErrorRow, 'duplicateRows' => $duplicateRows];
            }
            unset($headersCopy[$att]);
        }
        // dd($errorList);
        return $errorList;
    }

    public function arrayToStringValue(mixed $rows)
    {
        $result = '';
        foreach ($rows as $row => $value) {
            if (is_array($value)) {
                foreach ($value as $row2 => $value2) {
                    $result .= $value2;
                    if ($row2 != count($value) - 1) {
                        $result .= ', ';
                    }
                }
            } else {
                $result .= $value;
                if ($row != count($rows) - 1) {
                    $result .= ', ';
                }
            }
        }
        if (str_ends_with($result, ", ")) {
            $result = substr($result, 0, -2);
        }
        return $result;
    }

    public function arrayToString(mixed $rows, $startNumber = 0)
    {
        $result = '';
        foreach ($rows as $row => $value) {
            if (is_array($value)) {
                foreach ($value as $row2 => $value2) {
                    $result .= ' [' . $row2 + $startNumber . '] ' . $value2;
                    if ($row2 != count($value) - 1) {
                        $result .= ', ';
                    }
                }
            } else {
                $result .= ' [' . $row + $startNumber . '] ' . $value;
                if ($row != count($rows) - 1) {
                    $result .= ', ';
                }
            }
        }
        if (str_ends_with($result, ", ")) {
            $result = substr($result, 0, -2);
        }
        return $result;
    }

    public function adjustData($dataRows, $headers, $newAttribute)
    {
        /*
                     * 1.size doesnot match
                     * 2.size match but required fielders are empty
                     */

        $copyDataRows = $dataRows;
        foreach ($copyDataRows as $i => $row) {
            //add new attribute
            foreach ($newAttribute as $att) {
                $copyDataRows [$i] [] = '';
                //$copyDataRows [$i] [$att] = '';
                // dd($dataRows [$i]);
            }
        }

        $corruptedData = [];
        $correctData = [];
        $corruptedDataCounter = 1;
        foreach ($copyDataRows as $i => $row) {
            $rowNumber = $i + 1;


            //$matchCond = (count($dataRows [$i]) + count($newAttribute)) === count($headers);
            $matchCond = (count($row)) === count($headers);
            if (!$matchCond) {
                if (count($row) < 2) {
                    continue;
                }
                $corruptedData [$corruptedDataCounter++] = [
                    'Anzahl der Felder [' . count(
                        $row
                    ) . '], Anzahl der DatenbankHeader [' . count($headers) . ']',
                    $rowNumber
                ];
            } else {
                $NullAttributeListe = [];
                foreach ($row as $col => $value) {
                    /*  if ($col === count($row) - 1) {
                          foreach ($newAttribute as $att) {
                              $dataRows [$i] [$att] = '';
                          }
                      }
                    */
                    //dump($headers[$col]);
                    //dump($i, $headers[$col]['Field'],$headers[$col]['Null']);
                    $NullAttributeCond = $headers[$col]['Null'] === 'NO' && $value === '';
                    if ($NullAttributeCond) {
                        $NullAttributeListe [$col] = $headers[$col]['Field'];
                    }
                }
                if ($NullAttributeListe) {
                    $corruptedData [] = [
                        'Pflicht-Attribute : [' . $this->arrayToString(
                            $NullAttributeListe,
                            1
                        ) . '] ist/sind leer.',
                        $rowNumber
                    ];
                } else {
                    $correctData [] = $row;
                }
            }
        }
        return ['dataRows' => $correctData, 'corruptedData' => $corruptedData];
    }

    public function adjustDataHeaderKeys($databaseHeaders, $newAttributeInFile)
    {
        /*
         * 1. row size doesnt match headers size
         * 2.  mandatory field is null
         */
        //$headersSize = count($databaseHeaders);

        $headersSize = count($databaseHeaders);
        $data = $this->readData($databaseHeaders, $newAttributeInFile);

        /*
            if ($newAttributeInFile){
                $data =$this->addNewAttributeToDataRows($newAttributeInFile, $data);
            }
        */

        foreach ($data as $row => $value) {
            if (count($value) !== $headersSize) {
                dump('size doesnt match', $headersSize, count($value));
            } else {
                dump('match');
            }
        }
    }

    /**
     * @param $newAttribute
     * @param $dataRow
     * addes empty value to data-row in order to match database table-size
     */
    private function addNewAttributeToDataRows($newAttribute, $dataRow)
    {
        $newDataRows = $dataRow;
        foreach ($dataRow as $row => $value) {
            foreach ($newAttribute as $att => $value2) {
                $newDataRows[$row][$value2] = '';
            }
            //  array_push($dataRow[$row], [$newAttributeName => '']);
        }
        return $newDataRows;
    }

    public function detectDataType(array $headers, array $firstRowData)
    {
        $headersCopy = [];
        foreach ($firstRowData as $row => $data){
            if (array_key_exists($row, $headers)){
                $headersCopy[]= $this->detectType($data);
                /*   $headersCopy[]= [
                       'Field' => $headers[$row],
                       'Type' => $this->detectType($data)
                   ];
                */
                //dump($data, $this->detectType($data));
            }
        }
        return $headersCopy;
    }

    private function detectType($data){
        //TODO: detect type
        /*   $floatCond =  is_numeric($data) && (str_contains($data, '.') || str_contains($data, ','));
          // $dateCond =  strlen(trim($data)) > 4 && strtotime($data);
           $intCond =  is_numeric($data);

           //dd($floatCond, $dateCond, $intCond);
           if ($floatCond){
               return'float(p)';
           }elseif ($intCond){
               return'BIGINT(100)';
           }else{
               return 'TEXT';
           }
        */
        return 'TEXT';
    }

}