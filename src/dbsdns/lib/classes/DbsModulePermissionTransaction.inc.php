<?php

/** Keeps lifecycle changes to sys_user atomic without changing its schema. */
class DbsModulePermissionTransaction
{
    private $db;
    private $started = false;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function verify()
    {
        $table = $this->db->queryOneRecord(
            "SELECT ENGINE AS engine FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sys_user'"
        );
        if(
            !is_array($table) || !isset($table['engine'])
            || strcasecmp((string)$table['engine'], 'InnoDB') !== 0
            || $this->hasError()
        ) {
            throw new RuntimeException('Für atomare Modulzuweisungen wird sys_user mit InnoDB benötigt.');
        }
    }

    public function begin()
    {
        $this->verify();
        $this->execute('START TRANSACTION');
        $this->started = true;
    }

    public function commit()
    {
        $this->execute('COMMIT');
        $this->started = false;
    }

    public function rollback()
    {
        if($this->started) {
            try {
                $this->db->query('ROLLBACK');
            } catch (Throwable $exception) {
                // Closing the CLI connection also rolls back an open transaction.
            }
            $this->started = false;
        }
    }

    private function execute($sql)
    {
        if($this->db->query($sql) === false || $this->hasError()) {
            throw new RuntimeException('Die Modulzuweisungs-Transaktion ist fehlgeschlagen.');
        }
    }

    private function hasError()
    {
        return isset($this->db->errorMessage) && $this->db->errorMessage !== '';
    }
}
