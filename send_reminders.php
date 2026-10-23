<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$now = new DateTimeImmutable();
$from = $now->modify('+23 hours')->format('Y-m-d H:i:s');
$to = $now->modify('+24 hours')->format('Y-m-d H:i:s');
$q = db()->prepare("SELECT a.id,a.starts_at,u.name customer,u.email,sv.name service,st.name stylist FROM appointments a JOIN users u ON u.id=a.customer_id JOIN services sv ON sv.id=a.service_id JOIN stylists st ON st.id=a.stylist_id WHERE a.status='confirmed' AND a.reminder_sent_at IS NULL AND a.starts_at>? AND a.starts_at<=? ORDER BY a.starts_at LIMIT 100");
$q->execute([$from,$to]);
$sent = 0;
foreach ($q->fetchAll() as $appointment) {
    $body = "Hi {$appointment['customer']},\n\nA little reminder of your Luma Studio appointment tomorrow:\n{$appointment['service']} with {$appointment['stylist']}\n" . date_label($appointment['starts_at']) . "\n\nWe look forward to seeing you. You can manage your appointment from your account.\n" . app_url('page=account');
    if (send_email($appointment['email'], 'A little reminder · Luma Studio tomorrow', $body)) {
        $mark = db()->prepare('UPDATE appointments SET reminder_sent_at=? WHERE id=? AND reminder_sent_at IS NULL');
        $mark->execute([date('Y-m-d H:i:s'),$appointment['id']]);
        $sent += $mark->rowCount();
    }
}
fwrite(STDOUT, "Sent {$sent} reminder(s).\n");
