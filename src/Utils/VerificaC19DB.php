<?php
namespace Herald\GreenPass\Utils;

class VerificaC19DB
{

    const SQLITE_DB_NAME = 'verificac19.db';

    private $db_complete_path;

    /**
     * PDO instance
     *
     * @var \PDO
     */
    private $pdo;

    public function __construct()
    {
        $this->db_complete_path = FileUtils::getCacheFilePath(self::SQLITE_DB_NAME);
        $this->connect();
    }

    public function initUcvi()
    {
        if (! $this->checkUcviTable("ucvi")) {
            $this->createUcviTable();
        }
    }

    public function emptyList()
    {
        if ($this->checkUcviTable("ucvi")) {
            $sql = 'DELETE FROM ucvi';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
        }
    }

    /**
     * return in instance of the PDO object that connects to the SQLite database
     */
    private function connect(): \PDO
    {
        if ($this->pdo == null) {
            $this->pdo = new \PDO("sqlite:" . $this->db_complete_path);
        }
        return $this->pdo;
    }

    private function createUcviTable()
    {
        $command = 'CREATE TABLE IF NOT EXISTS ucvi (
                        revokedUcvi VARCHAR PRIMARY KEY
                      );';
        // execute the sql commands to create new table
        $this->pdo->exec($command);
    }

    private function checkUcviTable($name): bool
    {
        $tables = $this->getTableList();
        if (in_array($name, $tables)) {
            return TRUE;
        }
        return FALSE;
    }

    private function getTableList(): array
    {
        $stmt = $this->pdo->query("SELECT name
                                   FROM sqlite_master
                                   WHERE type = 'table'
                                   ORDER BY name");
        $tables = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $tables[] = $row['name'];
        }

        return $tables;
    }

    public function addRevokedUcviToUcviList(string $revokedUcvi)
    {
        $sql = 'INSERT OR IGNORE INTO ucvi(revokedUcvi) VALUES(:revokedUcvi)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':revokedUcvi', $revokedUcvi);
        $stmt->execute();

        return $this->pdo->lastInsertId();
    }

    public function addAllRevokedUcviToUcviList(array $revokedUcvi)
    {
        $this->pdo->beginTransaction();
        $insert_values = array();
        foreach ($revokedUcvi as $d) {
            $question_marks[] = '(' . $this->placeholders('?', is_array($d) ? sizeof($d) : 1) . ')';
            if (is_array($d)) {
                $insert_values = array_merge($insert_values, array_values($d));
            } else {
                $insert_values[] = $d;
            }
        }
        $sql = 'INSERT OR IGNORE INTO ucvi(revokedUcvi) VALUES ' . implode(',', $question_marks);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($insert_values);
        $this->pdo->commit();
    }

    private function placeholders($text, $count = 0, $separator = ",")
    {
        $result = array();
        if ($count > 0) {
            for ($x = 0; $x < $count; $x ++) {
                $result[] = $text;
            }
        }

        return implode($separator, $result);
    }

    public function removeRevokedUcviFromUcviList($revokedUcvi)
    {
        $sql = 'DELETE FROM ucvi WHERE revokedUcvi = :revokedUcvi';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':revokedUcvi', $revokedUcvi);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function getRevokedUcviList()
    {
        $stmt = $this->pdo->query('SELECT revokedUcvi FROM ucvi');
        $revokedUcvis = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $revokedUcvis[] = [
                'revokedUcvi' => $row['revokedUcvi']
            ];
        }
        return $revokedUcvis;
    }
}