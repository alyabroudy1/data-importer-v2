<?php

namespace App\Repository;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function PHPUnit\Framework\isEmpty;

class DataMappingRepository
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $currentTable;
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager, $table)
    {
        $this->connection = $entityManager->getConnection();
        $this->currentTable = $table;
        // $this->io = $io;
        $this->entityManager = $entityManager;
    }

    public function getExistingTables()
    {
        $sql = 'SHOW TABLES;';
        $statement = $this->connection->prepare($sql);
        try {
            $tableNames = $statement->executeQuery();
            return $tableNames->fetchAllAssociative();
        } catch (Exception $e) {
            dump($e->getMessage());
        }
    }

    public function isTableExist($tableName)
    {
        $tables = $this->getExistingTables();
        foreach ($tables as $table => $value) {
            if (in_array($tableName, $value)) {
                return true;
            }
        }
        return false;
    }

    public function getExistingTableAttribute($tableName)
    {
        $sql = 'SHOW COLUMNS FROM ' . $tableName . ';';
        $statement = $this->connection->prepare($sql);
        try {
            $attribute = $statement->executeQuery();
            $attribute = $attribute->fetchAllAssociative();
            // return $tableNames->fetchAllAssociative();
            $attributeHeader = [];
            $headersWithAttribute = [];
            foreach ($attribute as $att) {
                if ($att['Extra'] && $att['Extra'] === 'auto_increment') {
                    continue;
                }
                //dd($att);
                $attributeHeader [] = $att['Field'];
                $headersWithAttribute [] = $att;
            }
            return ['headers' => $attributeHeader, 'headersWithAttribute' => $headersWithAttribute];
        } catch (Exception $e) {
            dump($e->getMessage());
        }
    }

    /**
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    function truncate()
    {
        /* Clear Table*/
        $this->connection->executeQuery(
            $this->connection->getDatabasePlatform()->getTruncateTableSQL($this->currentTable, true)
        );
    }

    /**
     * @param $tableName
     * @param $rows
     * @throws \Doctrine\DBAL\Exception
     */
    public function creatTable($rows, $dataType)
    {

        try {
            if (!empty($rows)) {
                $sqlStatement = "CREATE TABLE IF NOT EXISTS " . $this->currentTable . " ( ";
                $sqlStatement .= "uid int(11) unsigned NOT NULL auto_increment,";
                foreach ($rows as $row => $value) {
                  $sqlStatement .= $this->addQuote(trim($value)) . " ".$dataType[$row].",";
                  // $sqlStatement .= $this->addQuote(trim($row)) . " ".$this->detectType($row).",";
                   //$sqlStatement .= $this->addQuote(trim($row['Field'])) . " ".$row['Type'].",";
                }
                $sqlStatement .= " PRIMARY KEY  (`uid`)) ";
            }

            $stmt = $this->connection->prepare($sqlStatement);
            $stmt->executeQuery();
        } catch (\Doctrine\DBAL\Exception $exception) {
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * /F070/ Daten Importieren
     */
    public function insertNewDataToDatabase($headers, $dataRow)
    {
        $sql = "";
        $numberOfAttributeToCompare = intval(count($headers) / 2);

        //  dump(implode("','" , $row));
        // dd($this->isExistingData($dataRow,$numberOfAttributeToCompare , $headers) );
        if ($this->isExistingData($dataRow, 2, $headers)) {
            return false;
        }
        $sql .= 'INSERT INTO ' . $this->currentTable . '(' . $this->rowsToString($headers) . ')' .
            ' VALUES (' .
            $this->rowsToString($dataRow, true)
            . ');';
        $statement = $this->connection->prepare($sql);
        try {
            //dd($sql);
            $queryResult = $statement->executeQuery();
            return true;
        } catch (Exception $e) {
            dump($e->getMessage());
            dump($sql);
        }
    }

    /**
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    protected function createQuery()
    {
        return $this->connection->createQueryBuilder();
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function getExistingAttribute(): array
    {
        $stmt = $this->connection->executeQuery('describe ' . $this->currentTable);

        $fields = [];
        foreach ($stmt->fetchAllAssociative() as $row) {
            $fields[] = $row['Field'];
        }
        return $fields;
    }

    private function castType($value)
    {
        $value = str_replace('"', "", $value);
        if (is_numeric($value)) {
            return intval($value);
        }
        return "'" . $value . "'";
    }

    private function addQuote($value, $isData = false)
    {
        $split = "`";
        if ($isData) {
            $split = "\"";
        }
        return $split . $value . $split;
    }

    private function rowsToString($rows, $isData = false)
    {
        $result = "";
        foreach ($rows as $row => $value) {
            $value = str_replace('"', "", $value);
            $result .= $this->addQuote($value, $isData);
            // $result .= $this->castType($value);
            if ($row != count($rows) - 1) {
                $result .= ', ';
            }
        }
        if (str_ends_with($result, ", ")) {
            $result = substr($result, 0, -2);
        }
        return $result;
    }

    private function isExistingData($dataRow, $numberOfAttribute, $headers)
    {
        $sql = 'SELECT ' . $this->rowsToString($headers) . " FROM " . $this->currentTable;

        $col = " WHERE ";
        for ($i = 0; $i < $numberOfAttribute; $i++) {
            $col .= $this->addQuote($headers[$i]) . " = " . $this->castType($dataRow[$i]);

            if ($i != $numberOfAttribute - 1) {
                $col .= " AND ";
            }
        }
        $sql .= $col . ";";
        $statement = $this->connection->prepare($sql);
        try {
            $statementResult = $statement->executeQuery();
            return $statementResult->rowCount() > 0;
        } catch (Exception $e) {
            dump($e->getMessage());
        }
    }

    public function addNewFieldToDatabase($newFieldName)
    {
        $sql = 'ALTER TABLE ' . $this->currentTable . ' ADD ' . $newFieldName . ' TEXT NOT NULL;';

        $statement = $this->connection->prepare($sql);
        try {
            $queryResult = $statement->executeQuery();
            return true;
        } catch (Exception $e) {
            dump($e->getMessage());
            dump($sql);
        }
    }
}