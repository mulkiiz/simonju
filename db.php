<?php
require_once __DIR__ . '/config.php';

function db() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_errno) {
            error_log('DB connect failed: ' . $conn->connect_error);
            http_response_code(500);
            die('Database connection error. Cek error.log.');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/**
 * Prepared query helper.
 * Contoh: q("SELECT * FROM jurnals WHERE id=?", "i", [$id]);
 *         q("INSERT INTO ... VALUES (?,?)", "ss", [$a, $b]);
 */
function q($sql, $types = '', $params = []) {
    $stmt = db()->prepare($sql);
    if (!$stmt) {
        error_log('Prepare failed: ' . db()->error . ' | SQL: ' . $sql);
        return false;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function fetch_one($sql, $types = '', $params = []) {
    $stmt = q($sql, $types, $params);
    if (!$stmt) return null;
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

function fetch_all($sql, $types = '', $params = []) {
    $stmt = q($sql, $types, $params);
    if (!$stmt) return [];
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

function exec_q($sql, $types = '', $params = []) {
    $stmt = q($sql, $types, $params);
    if (!$stmt) return false;
    $affected = $stmt->affected_rows;
    $insert_id = db()->insert_id;
    $stmt->close();
    return ['affected' => $affected, 'insert_id' => $insert_id];
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
