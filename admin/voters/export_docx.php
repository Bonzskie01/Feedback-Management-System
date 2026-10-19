<?php
// admin/voters/export_docx.php
// Downloads voter list as a properly formatted .docx (vms.docx style)

$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token.');
}

if (!verify_admin_password($conn, $_SESSION['admin_id'] ?? 0, $_POST['admin_password'] ?? '')) {
    http_response_code(403);
    exit('Admin password confirmation is required to export voters.');
}

ensure_voters_election_id_column($conn);

$search        = isset($_POST['search']) ? trim($_POST['search']) : '';
$status_filter = isset($_POST['status']) ? trim($_POST['status']) : '';

$where_clauses = []; $params = []; $param_count = 1;
if ($search) {
    $where_clauses[] = "(v.full_name ILIKE \$$param_count OR v.voter_id ILIKE \$$param_count)";
    $params[] = "%$search%"; $param_count++;
}
if ($status_filter && in_array($status_filter, ['active','voted'])) {
    $where_clauses[] = "v.status = \$$param_count";
    $params[] = $status_filter; $param_count++;
}
$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$result = pg_query_params($conn,
    "SELECT v.voter_id, v.full_name, v.grade_section, v.status, v.voted_at,
            v.election_id, e.title AS election_title
      FROM voters v
  LEFT JOIN elections e ON e.id = v.election_id
      $where_sql
   ORDER BY COALESCE(e.title, ''),
            NULLIF(regexp_replace(v.voter_id, '\D', '', 'g'), '')::integer ASC,
            v.voter_id ASC,
            v.full_name ASC",
    $params);

if (!$result) {
    die('Export failed.');
}

$voters = [];
$export_elections = [];
while ($row = pg_fetch_assoc($result)) {
    $election_title = $row['election_title'] ?: 'Unassigned';
    $export_elections[$election_title] = true;
    $voters[] = [
        'voter_id'      => $row['voter_id'],
        'full_name'     => $row['full_name'],
        'election_title'=> $election_title,
        'grade_section' => $row['grade_section'] ?: '',
        'status'        => ucfirst($row['status']),
        'voted_at'      => $row['voted_at'] ? date('M d, Y h:i A', strtotime($row['voted_at'])) : '',
    ];
}

$election_names = array_keys($export_elections);
$elec_title = count($election_names) === 1 ? $election_names[0] : 'All Elections';

$payload = [
    'voters'         => $voters,
    'generated_date' => date('F d, Y h:i A'),
    'election_title' => $elec_title,
    'school_name'    => school_name(),
];

$template_path = $root_path . '/templates/credentials_template.docx';

$template_validation_error = '';
if (file_exists($template_path) && extension_loaded('zip')
    && validate_safe_docx_template($template_path, $template_validation_error)) {
    try {
        download_export_using_template($voters, $payload, $template_path);
        exit;
    } catch (Exception $e) {
        // Fall back to the generated DOCX if the uploaded template is invalid.
    }
}

// Write temp JSON
$tmpJson   = sys_get_temp_dir() . '/vms_export_' . uniqid() . '.json';
$tmpDocx   = sys_get_temp_dir() . '/vms_export_' . uniqid() . '.docx';
$genScript = __DIR__ . '/../../gen_docx.js';
$logo1     = __DIR__ . '/../../assets/images/vms_logo1.png';
$logo2     = __DIR__ . '/../../assets/images/vms_logo2.png';

file_put_contents($tmpJson, json_encode($payload, JSON_UNESCAPED_UNICODE));

$cmd    = escapeshellcmd("node " . escapeshellarg($genScript)) .
          " export " .
          escapeshellarg($tmpJson) . " " .
          escapeshellarg($tmpDocx) . " " .
          escapeshellarg($logo1)   . " " .
          escapeshellarg($logo2)   . " 2>&1";

$output = shell_exec($cmd);

@unlink($tmpJson);

if (!file_exists($tmpDocx) || filesize($tmpDocx) < 100) {
    die('DOCX generation failed.');
}

audit_log($conn, 'voters_exported', 'voters', null, ['format' => 'docx', 'search' => $search, 'status' => $status_filter]);

$filename = 'voters_list_' . date('Ymd_His') . '.docx';
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpDocx));
header('Cache-Control: no-cache');
readfile($tmpDocx);
@unlink($tmpDocx);
exit;

function download_export_using_template($voters, $payload, $template_path) {
    $temp_file = sys_get_temp_dir() . '/vms_export_template_' . uniqid() . '.docx';

    if (!copy($template_path, $temp_file)) {
        throw new Exception('Failed to copy template.');
    }

    $zip = new ZipArchive();
    if ($zip->open($temp_file) !== true) {
        @unlink($temp_file);
        throw new Exception('Cannot open template as DOCX.');
    }

    $xml_content = $zip->getFromName('word/document.xml');
    if ($xml_content === false) {
        $zip->close();
        @unlink($temp_file);
        throw new Exception('Template missing document.xml.');
    }

    $voter_list = '';
    foreach ($voters as $index => $voter) {
        $voter_list .= ($index + 1) . '. ' .
            ($voter['full_name'] ?? '') .
            ' | ID: ' . ($voter['voter_id'] ?? '') .
            ' | Election: ' . ($voter['election_title'] ?? '') .
            ' | Grade/Section: ' . (($voter['grade_section'] ?? '') ?: '-') .
            ' | Status: ' . ($voter['status'] ?? '') .
            (($voter['voted_at'] ?? '') ? ' | Voted At: ' . $voter['voted_at'] : '') .
            "\n";
    }

    $has_voter_table_placeholder = strpos($xml_content, '{{VOTER_TABLE}}') !== false;
    $has_voter_list_placeholder = strpos($xml_content, '{{VOTER_LIST}}') !== false;

    $table_xml = build_export_voters_xml($voters);
    if ($has_voter_table_placeholder) {
        $xml_content = replace_docx_placeholder_paragraph($xml_content, '{{VOTER_TABLE}}', $table_xml);
    }

    $replacements = [
        '{{SCHOOL_NAME}}'    => $payload['school_name'] ?? '',
        '{{ELECTION_TITLE}}' => $payload['election_title'] ?? '',
        '{{GENERATED_DATE}}' => $payload['generated_date'] ?? '',
        '{{TOTAL_VOTERS}}'   => count($voters),
        '{{VOTER_LIST}}'     => trim($voter_list),
        '{{VOTER_TABLE}}'    => '',
    ];

    $xml_content = replace_export_docx_placeholders($xml_content, $replacements);

    if (!$has_voter_table_placeholder && !$has_voter_list_placeholder) {
        $xml_content = insert_export_before_docx_body_end($xml_content, $table_xml);
    }

    if (!$zip->addFromString('word/document.xml', $xml_content)) {
        $zip->close();
        @unlink($temp_file);
        throw new Exception('Failed to update template.');
    }

    $zip->close();

    if (!file_exists($temp_file) || filesize($temp_file) < 100) {
        @unlink($temp_file);
        throw new Exception('Generated template export is invalid.');
    }

    $filename = 'voters_list_' . date('Ymd_His') . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($temp_file));
    header('Cache-Control: no-cache');
    readfile($temp_file);
    @unlink($temp_file);
}

function replace_export_docx_placeholders($xml_content, $replacements) {
    foreach ($replacements as $placeholder => $value) {
        $xml_content = str_replace($placeholder, export_docx_escape_text($value), $xml_content);
    }

    return $xml_content;
}

function replace_docx_placeholder_paragraph($xml_content, $placeholder, $replacement_xml) {
    $pattern = '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*' . preg_quote($placeholder, '/') . '(?:(?!<\/w:p>).)*<\/w:p>/s';
    $new_xml = preg_replace($pattern, $replacement_xml, $xml_content, 1, $count);

    if ($count > 0) {
        return $new_xml;
    }

    return str_replace($placeholder, '', $xml_content) . $replacement_xml;
}

function insert_export_before_docx_body_end($xml_content, $insert_xml) {
    $body_end = '</w:body>';
    $pos = strrpos($xml_content, $body_end);

    if ($pos === false) {
        return $xml_content . $insert_xml;
    }

    return substr($xml_content, 0, $pos) . $insert_xml . substr($xml_content, $pos);
}

function export_docx_escape_text($value) {
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function build_export_docx_paragraph($text, $bold = false, $center = false) {
    $bold_xml = $bold ? '<w:b/>' : '';
    $center_xml = $center ? '<w:pPr><w:jc w:val="center"/></w:pPr>' : '';

    return '<w:p>' . $center_xml . '<w:r><w:rPr>' . $bold_xml . '</w:rPr><w:t>' .
        export_docx_escape_text($text) . '</w:t></w:r></w:p>';
}

function build_export_docx_cell($text, $bold = false) {
    $bold_xml = $bold ? '<w:b/>' : '';

    return '<w:tc><w:tcPr><w:tcW w:w="1560" w:type="dxa"/></w:tcPr><w:p><w:r><w:rPr>' .
        $bold_xml . '</w:rPr><w:t>' . export_docx_escape_text($text) . '</w:t></w:r></w:p></w:tc>';
}

function build_export_docx_row($cells, $header = false) {
    $xml = '<w:tr>';
    foreach ($cells as $cell) {
        $xml .= build_export_docx_cell($cell, $header);
    }
    return $xml . '</w:tr>';
}

function build_export_voters_xml($voters) {
    $xml = build_export_docx_paragraph('Voters List', true, true) .
        '<w:tbl><w:tblPr><w:tblW w:w="9360" w:type="dxa"/><w:tblBorders>' .
        '<w:top w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:left w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:bottom w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:right w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>' .
        '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>' .
        '</w:tblBorders></w:tblPr>' .
        build_export_docx_row(['#', 'Voter ID', 'Full Name', 'Election', 'Grade/Section', 'Status', 'Voted At'], true);

    foreach ($voters as $index => $voter) {
        $xml .= build_export_docx_row([
            (string) ($index + 1),
            $voter['voter_id'] ?? '',
            $voter['full_name'] ?? '',
            $voter['election_title'] ?? '',
            ($voter['grade_section'] ?? '') ?: '-',
            $voter['status'] ?? '',
            $voter['voted_at'] ?? '',
        ]);
    }

    return $xml . '</w:tbl>';
}
