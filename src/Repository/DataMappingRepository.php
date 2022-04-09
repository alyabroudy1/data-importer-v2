<?php

namespace App\Repository;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;

class DataMappingRepository
{
    /**
     * @var \Doctrine\DBAL\Connection connection to database
     */
    protected $connection;

    /**
     * @var string database table name
     */
    protected $currentTable;
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager, $table)
    {
        $this->connection = $entityManager->getConnection();
        $this->currentTable = $table;
        $this->entityManager = $entityManager;
    }

    /**
     * gets the existing database tables
     * @return \mixed[][]|void
     * @throws Exception
     */
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

    /**
     * check if table exist
     * @param $tableName string of the table
     * @return bool if table exist
     * @throws Exception
     */
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

    /**
     * get the attribute of the database table
     * @param $tableName
     * @return array[]|void
     * @throws Exception
     */
    public function getExistingTableAttribute($tableName)
    {
        $sql = 'SHOW COLUMNS FROM ' . $tableName . ';';
        $statement = $this->connection->prepare($sql);
        try {
            $attribute = $statement->executeQuery();
            $attribute = $attribute->fetchAllAssociative();
            $attributeHeader = [];
            $headersWithAttribute = [];
            foreach ($attribute as $att) {
                if ($att['Extra'] && $att['Extra'] === 'auto_increment') {
                    continue;
                }
                $attributeHeader [] = $att['Field'];
                $headersWithAttribute [] = $att;
            }
            return ['headers' => $attributeHeader, 'headersWithAttribute' => $headersWithAttribute];
        } catch (Exception $e) {
            dump($e->getMessage());
        }
    }

    /**
     * clear table data
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
     * creates a table in the database
     * @param $tableName string table name
     * @param $rows array attributes of the table
     * @throws \Doctrine\DBAL\Exception
     */
    public function creatTable($rows, $dataType)
    {
        try {
            if (!empty($rows)) {
                $sqlStatement = "CREATE TABLE IF NOT EXISTS " . $this->currentTable . " ( ";
                $sqlStatement .= "uid int(11) unsigned NOT NULL auto_increment,";
                foreach ($rows as $row => $value) {
                    $sqlStatement .= $this->addQuote(trim($value)) . " " . $dataType[$row] . ",";
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
     * insert data to database
     * @param $headers array data-headers
     * @param $dataRow array data-rows to be inserted
     * @return bool|void true if done without error
     * @throws Exception
     */
    public function insertNewDataToDatabase($headers, $dataRow)
    {
        $sql = "";
        $numberOfAttributeToCompare = intval(count($headers) / 2);
        if ($this->isExistingData($dataRow, 2, $headers)) {
            return false;
        }
        $sql .= 'INSERT INTO ' . $this->currentTable . '(' . $this->rowsToString($headers) . ')' .
            ' VALUES (' .
            $this->rowsToString($dataRow, true)
            . ');';
        $statement = $this->connection->prepare($sql);
        try {
            $queryResult = $statement->executeQuery();
            return true;
        } catch (Exception $e) {
            dump($e->getMessage());
            dump($sql);
        }
    }

    /**
     * create a queryBuilder object for the database
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    protected function createQuery()
    {
        return $this->connection->createQueryBuilder();
    }

    /**
     * cast a value into its proper quote single or double
     * @param $value string data value
     * @return int|string int if its decimal value or a string with proper quote
     */
    private function castType($value)
    {
        $value = str_replace('"', "", $value);
        if (is_numeric($value)) {
            return intval($value);
        }
        return "'" . $value . "'";
    }

    /**
     * cast a value into its proper quote single or double
     * @param $value string data value
     * @param $isData bool if its a data value and not a header
     * @return int|string int if its decimal value or a string with proper quote
     */
    private function addQuote($value, $isData = false)
    {
        $split = "`";
        if ($isData) {
            $split = "\"";
        }
        return $split . $value . $split;
    }

    /**
     * convert array to string with proper quote
     * @param $rows array rows
     * @param $isData bool if it's a data row
     * @return string converted row
     */
    private function rowsToString($rows, $isData = false)
    {
        $result = "";
        foreach ($rows as $row => $value) {
            $value = str_replace('"', "", $value);
            $result .= $this->addQuote($value, $isData);
            if ($row != count($rows) - 1) {
                $result .= ', ';
            }
        }
        if (str_ends_with($result, ", ")) {
            $result = substr($result, 0, -2);
        }
        return $result;
    }

    /**
     * @param $dataRow array of data to be inserted in to database
     * @param $numberOfAttribute int number of attribute to compare with.
     * @param $headers array database headers
     * @return bool|void true if done without error
     * @throws Exception
     */
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

    /**
     * adds new attribute to database table
     * @param $newFieldName string attribute name
     * @return bool|void true if done without error
     * @throws Exception
     */
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