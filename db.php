<?php
declare(strict_types=1);
/*
	https://github.com/mohroyhannahado/Pasti-CronWorker
*/
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

class DB {
    public $conn;
    public function __construct() {
        // GANTI KREDENSIAL
        $host = 'localhost';		// 127.0.0.1 atau IP server mysql
        $user = 'root';				// user mysql
        $pass = 'password';			// password mysql
        $name = 'nama_database';	// database
        $port = 3306;				// port

        $this->conn = new mysqli($host, $user, $pass, $name, $port);
        $this->conn->set_charset('utf8mb4');
        $this->conn->query("SET time_zone = '+07:00'");
    }

    private function bindAndExec(mysqli_stmt $stmt, string $types, array $params): void {
        if ($types !== '') {
            if (strlen($types) !== count($params)) {
                throw new RuntimeException(
                    "bind_param mismatch: types(".strlen($types).") vs params(".count($params).")" .
                    " | TYPES='{$types}' | SQL='{$stmt->sqlstate}'"
                );
            }
            // bind_param harus by-reference
            $bind = [$types];
            foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
    }

    public function fetchAll(string $sql, string $types = '', array $params = []): array {
        $stmt = $this->conn->prepare($sql);
        $this->bindAndExec($stmt, $types, $params);
        $res = $stmt->get_result();
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    public function exec(string $sql, string $types = '', array $params = []): int {
        $stmt = $this->conn->prepare($sql);
        $this->bindAndExec($stmt, $types, $params);
        return $stmt->affected_rows;
    }
}
