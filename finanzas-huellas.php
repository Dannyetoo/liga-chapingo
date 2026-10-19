<?php
/**
 * Liga Chapingo — Finanzas · HUELLAS (historial de estado.json).
 *
 * Tesorería borra y modifica cobros y gastos directamente. Para no perder el historial,
 * cada vez que Administración abre Finanzas y estado.json tiene una revisión nueva:
 *   - se guarda cada VERSIÓN distinta de cada cobro, gasto y multa (hash sha256);
 *   - se marca con eliminado_en lo que ya no aparece.
 * Sólo lee estado.json; sólo escribe en fin_huellas. Nunca borra nada.
 */
declare(strict_types=1);

/** JSON canónico (claves ordenadas) para que el hash no dependa del orden. */
function fin_json_canonico($x): string
{
    $ord = function ($v) use (&$ord) {
        if (!is_array($v)) return $v;
        $esLista = array_keys($v) === range(0, count($v) - 1);
        if (!$esLista) ksort($v, SORT_STRING);
        return array_map($ord, $v);
    };
    return (string)json_encode($ord($x), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

/**
 * Sincroniza si hace falta. Devuelve ['sincronizado' => bool, 'nuevas' => int, 'eliminadas' => int].
 * Si la tabla no existe o algo falla, no interrumpe la página.
 */
function fin_sincronizar_huellas(array $d): array
{
    $r = ['sincronizado' => false, 'nuevas' => 0, 'eliminadas' => 0];
    if (!fin_tabla_existe('fin_huellas')) return $r;
    $rev = (int)($d['rev'] ?? 0);
    $pdo = db();
    try {
        $ultima = (int)$pdo->query('SELECT COALESCE(MAX(rev_estado), 0) FROM fin_huellas')->fetchColumn();
        if ($rev > 0 && $rev <= $ultima) return $r;          // ya está al día

        $S = $d['estado'];
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT IGNORE INTO fin_huellas (origen, origen_id, hash, datos, rev_estado, visto_ultimo)
                              VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
        $ver = $pdo->prepare('UPDATE fin_huellas SET visto_ultimo = CURRENT_TIMESTAMP, eliminado_en = NULL
                               WHERE origen = ? AND origen_id = ? AND hash = ?');
        $presentes = [];
        foreach (['mov' => 'movs', 'gasto' => 'gastos', 'multa' => 'multas'] as $origen => $lista) {
            foreach (is_array($S[$lista] ?? null) ? $S[$lista] : [] as $x) {
                if (!is_array($x) || !isset($x['id']) || !is_scalar($x['id']) || (string)$x['id'] === '') continue;
                $id   = substr((string)$x['id'], 0, 64);
                $json = fin_json_canonico($x);
                $hash = hash('sha256', $json);
                $ins->execute([$origen, $id, $hash, $json, $rev]);
                if ($ins->rowCount() > 0) $r['nuevas']++;
                else $ver->execute([$origen, $id, $hash]);
                $presentes[$origen][$id] = $hash;
            }
        }
        // Lo que tenía huella viva y ya no está (o cuya versión cambió) queda marcado.
        $vivas = $pdo->query('SELECT id, origen, origen_id, hash FROM fin_huellas WHERE eliminado_en IS NULL')->fetchAll();
        $marca = $pdo->prepare('UPDATE fin_huellas SET eliminado_en = CURRENT_TIMESTAMP WHERE id = ?');
        foreach ($vivas as $h) {
            $actual = $presentes[$h['origen']][$h['origen_id']] ?? null;
            if ($actual !== $h['hash']) {
                $marca->execute([(int)$h['id']]);
                if ($actual === null) $r['eliminadas']++;
            }
        }
        $pdo->commit();
        $r['sincronizado'] = true;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[finanzas] huellas: ' . $ex->getMessage());
    }
    return $r;
}

/** Resumen del historial para mostrar en el panel. */
function fin_resumen_huellas(): ?array
{
    if (!fin_tabla_existe('fin_huellas')) return null;
    $f = db()->query("SELECT
            COUNT(DISTINCT CONCAT(origen, ':', origen_id))                                  AS registros,
            SUM(CASE WHEN eliminado_en IS NOT NULL THEN 1 ELSE 0 END)                       AS versiones_retiradas,
            MAX(rev_estado)                                                                  AS rev
          FROM fin_huellas")->fetch() ?: [];
    $borrados = (int)db()->query("SELECT COUNT(*) FROM (
            SELECT origen, origen_id FROM fin_huellas GROUP BY origen, origen_id
            HAVING SUM(eliminado_en IS NULL) = 0) x")->fetchColumn();
    return [
        'registros' => (int)($f['registros'] ?? 0),
        'borrados'  => $borrados,
        'rev'       => (int)($f['rev'] ?? 0),
    ];
}
