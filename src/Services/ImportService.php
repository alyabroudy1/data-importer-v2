<?php

namespace App\Services;

use App\Repository\DataMappingRepository;

abstract class ImportService
{

    /**
     * @var DataMappingRepository $dataMappingRepository for mapping data to database
     */
    protected $dataMappingRepository;

    /**
     * get tableName, tableHeaders, und dataRows from csv file
     * @return array String[][] containing tableName, tableHeaders, und dataRows
     */
    abstract public function readDataHeaders();

    /**
     * @param $headers string[] data-headers
     * @param $newAttribute string[] new attributes to be added to data-headers in order to match database headers. if any.
     * @return string[][] data-rows from the file
     */
    abstract public function readData($headers, $newAttribute);

    /**
     * @return mixed Data-mapping class
     */
    abstract public function getDataMappingRepository();

    /**
     * /F040/ Daten Spalten mit Datenbank vergleichen und Mappen
     * @param $newAttribute string[] new attributes to be added to data-headers in order to match database headers. if any.
     * @param $databaseHeaders string[] database-headers
     * @return array[] compare result with 'match' and 'noMatch' attribute list from headers
     */
    public function compareNewDataToDatabaseOri($newAttribute, $databaseHeaders)
    {
        $noMatchList = [];
        $matchList = [];
        $copyNewAttribute = $newAttribute;
        $anzahlDatabaseHeaders = count($databaseHeaders);
        $anzahlNewHeaders = count( $copyNewAttribute);

        $anzahlLoop = count($databaseHeaders);
        $counter = max($anzahlNewHeaders, $anzahlDatabaseHeaders);

        if ($anzahlNewHeaders < $anzahlDatabaseHeaders) {
            $anzahlLoop = $anzahlNewHeaders;
        }
        for ($i = 0; $i < $counter; $i++) {
            //check if number of fileAttribute equals the number of dbAttribute
            if ($anzahlDatabaseHeaders === $anzahlNewHeaders){
                dump('header size doesnt match');
            }

            if ($i < $anzahlDatabaseHeaders){
                //order match
                if ($databaseHeaders[$i] === $newAttribute[$i]){
                    dump('match: '.$i, $databaseHeaders[$i]);
                    $matchList[] = ['db'=> ['key' => $i, 'name' => $databaseHeaders[$i]], 'file'=>['key' => $i, 'name' => $newAttribute[$i]] ];
                }//order error
                else{
                    dump('NoMatch: '.$i, $databaseHeaders[$i]);
                    $fileAttribute = array_search($databaseHeaders[$i],  $copyNewAttribute, true);
                    dump('header: '.$databaseHeaders[$i]);
                    if ($fileAttribute){
                        // dump('inArray:', $fileAttribute, $copyNewAttribute[$fileAttribute]);
                        $matchList[] = [ 'db'=>['key' => $i, 'name' => $databaseHeaders[$i]], 'file'=>['key' => $fileAttribute, 'name' => $newAttribute[$fileAttribute]] ];
                    }
                    // dd('stop', $matchList);
                }
            }else{

            }

        }
/*
        for ($i = 0; $i < $counter; $i++) {
            //if dbAttribute header is empty
            if (empty($databaseHeaders[$i])) {
                $noMatchList [] = array('[leer]',  $copyNewAttribute[$i]);
            }//if fileAttribute header is empty
            elseif (empty( $copyNewAttribute[$i])) {
                $noMatchList [] = [$databaseHeaders[$i], '[leer]'];
            }
            elseif ($databaseHeaders[$i] ===  $copyNewAttribute[$i]) {
                $matchList [] = $databaseHeaders[$i];
            } else {
                //reconstruct nomatchList
                foreach ($noMatchList as $noMatchRow) {
                    $databaseAttribute = $noMatchRow[0];
                    dump($databaseAttribute);
                    $fileAttribute = array_search($databaseAttribute,  $copyNewAttribute, true);
                    if ($fileAttribute){
                        dump('inArray:', $fileAttribute, $copyNewAttribute[$fileAttribute]);
                    }
                    dd('file:', $copyNewAttribute);
                    dd($noMatchRow);
                }

                $noMatchList [] = [$databaseHeaders[$i],  $copyNewAttribute[$i]];
            }
        }
*/


        dump('match:',$matchList);
       // dump('noMatch:',$noMatchList);
       // dd('nomatch ');
        return ['match' => $matchList, 'noMatch' => $noMatchList];
    }

    public function detectNewFields($newAttribute, $databaseHeaders){
        $fileDiff = [ 'location' => 'file', 'attribute' => array_diff($newAttribute, $databaseHeaders)];
        $dbDiff = [ 'location' => 'db', 'attribute' => array_diff($databaseHeaders, $newAttribute)];
        return ['file'=>$fileDiff, 'db'=>$dbDiff];
    }
    public function compareNewDataToDatabase($newAttribute, $databaseHeaders)
    {
        $noMatchList = [];
        $matchList = [];
        $copyNewAttribute = $newAttribute;
        $anzahlDatabaseHeaders = count($databaseHeaders);
        $anzahlNewHeaders = count( $copyNewAttribute);

        //identify db and file headers
        $maxCounter = max($anzahlNewHeaders, $anzahlDatabaseHeaders);

        foreach ($databaseHeaders as $dKey => $dValue) {
            $fileAttribute = array_search($dValue, $copyNewAttribute, true);
            //validate attribute
            //order match
          if ($fileAttribute !== false) {
              if ($fileAttribute === 0 && $copyNewAttribute[$fileAttribute] === $dValue){
                  $matchList[] = ['db'=> ['key' => $dKey, 'name' => $dValue], 'file'=>['key' => $fileAttribute, 'name' => $copyNewAttribute[$fileAttribute]] ];
              }else{
                  $noMatchList[] = [
                      'error' => 'order_error',
                      'db' => ['key' => $dKey, 'name' => $dValue],
                      'file' => ['key' => $fileAttribute, 'name' => $copyNewAttribute[$fileAttribute]]
                  ];
              }
            }else{
              $noMatchList[] = [
                  'error'=>'extra_field_in_datenbank',
                  'db'=> ['key' => $dKey, 'name' => $dValue],
                  'file'=>['key' => null, 'name' => null]
              ];
            }
            unset($databaseHeaders[$dKey]);
            unset($copyNewAttribute[$fileAttribute]);
        }
        if ($copyNewAttribute){
            foreach ($copyNewAttribute as $key => $value){
                $noMatchList[] = [
                    'error'=>'extra_field_in_file',
                    'db'=> ['key' => null, 'name' => null],
                    'file'=>['key' => $key, 'name' => $value]
                ];
                unset($copyNewAttribute[$key]);
            }
        }
        return ['match' => $matchList, 'noMatch' => $noMatchList];
    }

    /**
     * @param $newFieldName string attribute name
     * @param $databaseHeaders array database-headers
     * @return array new database-headers
     */
    public function addNewFieldToDatabase($newFieldName, $databaseHeaders)
    {
        $this->dataMappingRepository->addNewFieldToDatabase($newFieldName);
        $databaseHeaders[] = $newFieldName;
        return $databaseHeaders;
    }

    /**
     * @param $headers array data-headers
     * @return array validation result
     */
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
                $strangeNameCond = $value === '' || is_numeric($value) || strlen($value) < 2;
                $strangeNameCond2 = strlen($value) > 75 || preg_match('/[\'^£$%&*()}{@#~?><>\/,.:|=+¬]/', $value);
                if ($strangeNameCond || $strangeNameCond2) {
                    if (strlen($value) > 30) {
                        $value = substr($value, 0, 20);
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
        return $errorList;
    }

    /**
     * @param array $rows arrays to convert its values to string
     * @return string converted array
     */
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

    /**
     * @param array $rows arrays to convert its keys and values to string
     * @param int $startNumber starting reference number with a 0 or 1
     * @return string converted array
     */
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

    /**
     * @param $dataRows array Data to be adjusted to match data-headers
     * @param $headers array data-headers
     * @param $newAttribute array in any new attributes to be added to data-row in order to match data-headers
     * @return array adjustment data-result adjusted data and corrupted data
     */
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
            }
        }

        $corruptedData = [];
        $correctData = [];
        $corruptedDataCounter = 1;
        foreach ($copyDataRows as $i => $row) {
            $rowNumber = $i + 1;
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

    /**
     * @param array $headers data-headers
     * @param array $firstRowData example of data-values
     * @return array data-headers with its data-type
     */
    public function detectDataType(array $headers, array $firstRowData)
    {
        $headersCopy = [];
        foreach ($firstRowData as $row => $data) {
            if (array_key_exists($row, $headers)) {
                $headersCopy[] = $this->detectType($data);
            }
        }
        return $headersCopy;
    }

    /**
     * @param $data string data-value to detect its data-type
     * @return string data-type
     */
    private function detectType($data)
    {
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