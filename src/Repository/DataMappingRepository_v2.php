<?php

namespace App\Repository;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DataMappingRepository_v2
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $currentTable;
    private SymfonyStyle $io;

    public function __construct(EntityManagerInterface $entityManager, $table, SymfonyStyle $io)
    {
        $this->connection = $entityManager->getConnection();
        $this->currentTable = $table;
        $this->io = $io;
    }

    public function getExistingTables(){
        $sql = 'SHOW TABLES;';
        $statement = $this->connection->prepare($sql);
        try {
            $tableNames = $statement->executeQuery();
            return $tableNames->fetchAllAssociative();
        } catch (Exception $e) {
            dump($e->getMessage());
        }
    }

    public function isExistingTable($tableName){
        $tables = $this->getExistingTables();
        foreach ($tables as $table => $value){
            if (in_array($tableName, $value))
            {
                return true;
            }
        }
        return false;
    }

    public function getExistingTableAttribute($tableName){
        $sql = 'SHOW COLUMNS FROM '.$tableName.';';
        $statement = $this->connection->prepare($sql);
        try {
            $attribute = $statement->executeQuery();
            $attribute= $attribute->fetchAllAssociative();
           // return $tableNames->fetchAllAssociative();
            $attributeHeader = [];
            foreach ($attribute as $att){
                if ($att['Extra'] && $att['Extra'] === 'auto_increment'){
                    continue;
                }
                $attributeHeader []= $att['Field'];
            }
        return $attributeHeader;
        } catch (Exception $e) {
            dump($e->getMessage());
        }
    }

    public function compareToDatabase($newAttribute, $existingAttribute){
        $noMatchList=[];
        $matchList=[];
        for ($i = 0; $i < count($existingAttribute); $i++){
            if ($existingAttribute[$i] === $newAttribute[$i]){
                $matchList [] = $existingAttribute[$i];
            }else{
                dump($existingAttribute[$i], $newAttribute[$i]);
                $noMatchList [] = $newAttribute[$i];
            }
        }
        return ['match'=> $matchList, 'noMatch'=> $noMatchList];
    }


    /**
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    function truncate(){
        /* Clear Table*/
        $this->connection->executeQuery(
            $this->connection->getDatabasePlatform()->getTruncateTableSQL($this->currentTable, true)
        );
    }
    /**
     * @param $tableName
     * @param $rows
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    public function creatTable($rows){
        try {
            if (!empty($rows)){
                $sqlStatement = "CREATE TABLE IF NOT EXISTS " . $this->currentTable . " ( ";
                $sqlStatement .= "uid int(11) unsigned NOT NULL auto_increment,";
                foreach ($rows as $row) {
                    /* @TODO Check property Types */
                  //  var_dump($row);
                    //$sqlStatement .= strtolower(trim($row)) . " varchar(255) NOT NULL default '',";
                    //$sqlStatement .= $this->addQuote(trim($row)) . " varchar(255) NOT NULL default '',";
                    $sqlStatement .= $this->addQuote(trim($row)) . " TEXT NOT NULL,";
                }
                $sqlStatement .= " PRIMARY KEY  (`uid`)) ";
            }

            $stmt = $this->connection->prepare($sqlStatement);
            $stmt->executeQuery();
            $this->io->success('Table ['.$this->currentTable.'] mit folgende Attribute wurde erstellt.');
            $this->io->table($rows, []);
        } catch (\Doctrine\DBAL\Exception $exception){
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function insert($headers, $dataRows){
        $duplicate = 0;
        $this->io->progressStart(count($dataRows));

        foreach ($dataRows as $row){
            $sql="";
          //  dump(implode("','" , $row));
            if ($this->isExistingData($row,2 , $headers)) {
             //   dump("exist");
                $duplicate++;
                continue;
            }
            $sql .= 'INSERT INTO '.$this->currentTable . '('.$this->rowsToString($headers). ')'.
                ' VALUES (' .
                $this->rowsToString($row, true)
                . ');';
           // dump($sql);

            if (!$sql){
               // dump("no data");
                return;
            }

            $statement = $this->connection->prepare($sql);
            try {
                $queryResult = $statement->executeQuery();
                $this->io->progressAdvance();
            } catch (Exception $e) {
                dump($e->getMessage());
                dump($sql);
            }
        }

        $this->io->progressFinish();
        if ($duplicate > 0){
            $this->io->info("[".$duplicate."] Doppelte Datensätze wurde gefunden");
        }


  /*
        $query = $this->createQuery();
       dump( $query
            ->insert($this->currentTable)
            ->values(" ' ".implode("', '" , $dataRows) . "' ")
            ->getSQL()  );



/*        foreach ($dataRows as $row){
            //todo find if data already exist
            dump(implode(', ', $row));
            $sql = 'INSERT INTO '.$this->currentTable . '('.implode(', ', $headers) . ')'.
                'VALUES (' .implode(', \'', $row). ');';
            $statement = $this->connection->prepare($sql);
            try {
                $queryResult = $statement->executeQuery();
            } catch (Exception $e) {
                dump($e->getMessage());
            }
               }
        */
    }

    public function castType($value){
        if (is_numeric($value)){
            return intval($value);
        }
        return "'".$value."'";
    }

    public function addQuote($value, $isData = false){
        $split = "`";
        if ($isData){
            $split = "\"";
        }
        return $split.$value.$split;
    }

    public function rowsToString($rows, $isData = false){
        $result = "";
        foreach ($rows as $row => $value){
            $result .= $this->addQuote($value, $isData);
            if ($row != count($rows)-1){
                $result .= ', ';
            }
        }
        if (str_ends_with($result, ", ")){
            $result = substr($result, 0, -2);
        }
        return $result;
    }

    public function isExistingData($dataRow, $numberOfAttribute, $headers){
        $sql = 'SELECT '. $this->rowsToString($headers) . " FROM ".$this->currentTable;

        $col = " WHERE ";
        for ($i = 0; $i < $numberOfAttribute; $i++){
            $col .= $this->addQuote($headers[$i]) . " = " . $this->castType( $dataRow[$headers[$i]] );

            if ($i != $numberOfAttribute -1){
                $col .=  " AND ";
            }
        }
        $sql .= $col .";";
        $statement = $this->connection->prepare($sql);
        try {
            $statementResult = $statement->executeQuery();
            return $statementResult->rowCount() > 0;
        } catch (Exception $e) {
            dump($e->getMessage());
        }
    }

    public function update(){

    }

    /**
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    protected function createQuery(){
        return $this->connection->createQueryBuilder();
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function getFields(): array
    {
        $stmt = $this->connection->executeQuery('describe ' . $this->currentTable);

        $fields = [];
        foreach ($stmt->fetchAllAssociative() as $row) {
            $fields[] = $row['Field'];
        }
        return $fields;
    }
}