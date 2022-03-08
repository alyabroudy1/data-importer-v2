<?php

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;

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

    public function __construct(EntityManagerInterface $entityManager, $table)
    {
        $this->connection = $entityManager->getConnection();
        $this->currentTable = $table;
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
                    $sqlStatement .= strtolower(trim($row)) . " varchar(255) NOT NULL default '',";
                }
                $sqlStatement .= " PRIMARY KEY  (`uid`)) ";
            }
            $stmt = $this->connection->prepare($sqlStatement);
            $stmt->executeQuery();
        } catch (\Doctrine\DBAL\Exception $exception){
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function insert($array){
        $query = $this->createQuery();
        $query
            ->insert($this->currentTable)
            ->values($array)
            ->executeQuery();
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