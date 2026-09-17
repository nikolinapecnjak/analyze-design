<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

function clean_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

function required_field(array $data, string $key): string
{
    $value = trim((string)($data[$key] ?? ''));
    if ($value === '') {
        throw new InvalidArgumentException("Missing field: $key");
    }
    return $value;
}

function mime_encode_subject(string $subject): string
{
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

function extract_cv_attachment(): ?array
{
    if (empty($_FILES['cv']['name']) || ($_FILES['cv']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES['cv'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('CV upload failed');
    }

    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        throw new InvalidArgumentException('CV file is too large (max 5MB)');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

    if ($ext !== 'pdf' || $mime !== 'application/pdf') {
        throw new InvalidArgumentException('CV must be a PDF file');
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);

    return [
        'name' => $safeName !== '' ? $safeName : 'cv.pdf',
        'content' => file_get_contents($file['tmp_name']),
    ];
}

$to = 'info@analyze-design.hr';

try {
    // Honeypot: real visitors never fill this hidden field.
    if (!empty($_POST['website'])) {
        echo json_encode(['ok' => true]);
        exit;
    }

    $type = (string)($_POST['type'] ?? '');
    $lang = ($_POST['lang'] ?? 'en') === 'hr' ? 'hr' : 'en';

    if (!in_array($type, ['company', 'apply', 'mentorship'], true)) {
        throw new InvalidArgumentException('Invalid form type');
    }

    $name = clean_header_value(required_field($_POST, 'name'));
    $email = clean_header_value(required_field($_POST, 'email'));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email');
    }

    if ($type === 'company') {
        $company = clean_header_value((string)($_POST['company'] ?? ''));
        $message = trim(required_field($_POST, 'message'));
        $subject = ($lang === 'hr' ? 'Upit s web stranice — ' : 'Website inquiry — ') . $name;
        $lines = $lang === 'hr'
            ? ['Ime: ' . $name, 'Tvrtka: ' . $company, 'Email: ' . $email, '', $message]
            : ['Name: ' . $name, 'Company: ' . $company, 'Email: ' . $email, '', $message];
    } elseif ($type === 'apply') {
        $role = clean_header_value(required_field($_POST, 'role'));
        $experience = trim((string)($_POST['experience'] ?? ''));
        $link = trim((string)($_POST['link'] ?? ''));
        $availability = trim((string)($_POST['availability'] ?? ''));
        $subject = ($lang === 'hr' ? 'Prijava kontraktora — ' : 'Contractor application — ') . $name . ' — ' . $role;
        $lines = $lang === 'hr'
            ? ['Ime: ' . $name, 'Email: ' . $email, 'Uloga / vještina: ' . $role, 'Godine iskustva: ' . $experience, 'LinkedIn / portfolio: ' . $link, 'Dostupnost: ' . $availability]
            : ['Name: ' . $name, 'Email: ' . $email, 'Role / skill: ' . $role, 'Years of experience: ' . $experience, 'LinkedIn / portfolio: ' . $link, 'Availability: ' . $availability];
    } else { // mentorship
        $role = clean_header_value(required_field($_POST, 'role'));
        $level = trim((string)($_POST['level'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $subject = ($lang === 'hr' ? 'Zahtjev za mentorstvo — ' : 'Mentorship request — ') . $name . ' — ' . $role;
        $lines = $lang === 'hr'
            ? ['Ime: ' . $name, 'Email: ' . $email, 'Ciljana uloga: ' . $role, 'Trenutna razina: ' . $level, '', $message]
            : ['Name: ' . $name, 'Email: ' . $email, 'Target role: ' . $role, 'Current level: ' . $level, '', $message];
    }

    $body = implode("\n", $lines);
    $attachment = extract_cv_attachment();

    $baseHeaders = "From: Analyze Design Website <info@analyze-design.hr>\r\n"
        . "Reply-To: {$name} <{$email}>\r\n"
        . "MIME-Version: 1.0\r\n";

    if ($attachment === null) {
        $headers = $baseHeaders . 'Content-Type: text/plain; charset=UTF-8';
        $sent = mail($to, mime_encode_subject($subject), $body, $headers);
    } else {
        $boundary = 'bnd_' . bin2hex(random_bytes(16));
        $headers = $baseHeaders . "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

        $message = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $body . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: application/pdf; name=\"{$attachment['name']}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"{$attachment['name']}\"\r\n\r\n"
            . chunk_split(base64_encode($attachment['content'])) . "\r\n"
            . "--{$boundary}--";

        $sent = mail($to, mime_encode_subject($subject), $message, $headers);
    }

    if (!$sent) {
        throw new RuntimeException('Mail could not be sent');
    }

    echo json_encode(['ok' => true]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
