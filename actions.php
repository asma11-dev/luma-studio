<?php
declare(strict_types=1);

function make_reset(int $userId, string $email, string $name): void
{
    $token = bin2hex(random_bytes(32));
    $q = db()->prepare('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,?)');
    $q->execute([$userId, hash('sha256', $token), (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s')]);
    $url = app_url('page=reset&token=' . rawurlencode($token));
    send_email($email, 'Set your Luma Studio password', "Hi {$name},\n\nUse this link within one hour to set your password:\n{$url}\n\nIf you did not request this, you can ignore this email.");
}

function customer_can_access(array $user, int $customerId): bool
{
    if ($user['role'] === 'owner') return true;
    if ($user['role'] !== 'stylist') return false;
    $q = db()->prepare('SELECT 1 FROM appointments a JOIN stylists s ON s.id=a.stylist_id WHERE s.user_id=? AND a.customer_id=? LIMIT 1');
    $q->execute([$user['id'], $customerId]);
    return (bool)$q->fetchColumn();
}

$action = (string)($_POST['action'] ?? '');
try {
    check_csrf();
    switch ($action) {
        case 'register':
            if (!throttle('register', 5)) throw new DomainException('Please wait a little before trying again.');
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) throw new DomainException('Enter your name, a valid email and a password of at least 10 characters.');
            $q = db()->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'customer')");
            try { $q->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]); }
            catch (PDOException $e) { if ($e->getCode() === '23000') throw new DomainException('An account with that email already exists.'); throw $e; }
            session_regenerate_id(true); $_SESSION['user_id'] = (int)db()->lastInsertId();
            flash('Your account is ready. Let’s find your appointment.'); redirect('book');

        case 'login':
            if (!throttle('login', 10)) throw new DomainException('Too many attempts. Please wait 15 minutes before trying again.');
            $q = db()->prepare('SELECT id,password_hash FROM users WHERE email=? AND is_active=1');
            $q->execute([strtolower(trim((string)($_POST['email'] ?? '')))]);
            $user = $q->fetch();
            if (!$user || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) throw new DomainException('Email or password not recognized.');
            session_regenerate_id(true); $_SESSION['user_id'] = (int)$user['id'];
            flash('Welcome back.'); redirect('account');

        case 'logout':
            $_SESSION = [];
            if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']); }
            session_destroy(); redirect('home');

        case 'forgot':
            if (!throttle('forgot', 4)) throw new DomainException('Please wait before requesting another link.');
            $q = db()->prepare('SELECT id,name,email FROM users WHERE email=? AND is_active=1');
            $q->execute([strtolower(trim((string)($_POST['email'] ?? '')))]);
            if ($u = $q->fetch()) make_reset((int)$u['id'], $u['email'], $u['name']);
            flash('If that address has an account, a password reset link is on its way.'); redirect('forgot');

        case 'reset':
            $token = (string)($_POST['token'] ?? ''); $password = (string)($_POST['password'] ?? '');
            if (strlen($password) < 10) throw new DomainException('Choose a password of at least 10 characters.');
            $q = db()->prepare('SELECT id,user_id FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>?');
            $q->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
            $reset = $q->fetch();
            if (!$reset) throw new DomainException('That link has expired. Request a new one.');
            db()->beginTransaction();
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
            db()->prepare('UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute([date('Y-m-d H:i:s'), $reset['user_id']]);
            db()->commit();
            flash('Password updated. You can sign in now.'); redirect('login');

        case 'book':
            $user = require_login();
            if ($user['role'] !== 'customer') throw new DomainException('Customer accounts are used to place appointments.');
            if (!throttle('book', 8, 3600)) throw new DomainException('Please contact the studio to arrange several appointments.');
            $serviceId = (int)($_POST['service_id'] ?? 0); $stylistId = (int)($_POST['stylist_id'] ?? 0);
            $start = (string)($_POST['starts_at'] ?? '');
            $id = (new BookingService(db()))->create((int)$user['id'], $serviceId, $stylistId, $start, (string)($_POST['customer_note'] ?? ''));
            $q = db()->prepare('SELECT a.starts_at,sv.name service,st.name stylist,u.email,u.name customer FROM appointments a JOIN services sv ON sv.id=a.service_id JOIN stylists st ON st.id=a.stylist_id JOIN users u ON u.id=a.customer_id WHERE a.id=?');
            $q->execute([$id]); $a = $q->fetch();
            $sent = send_email($a['email'], 'Appointment confirmed at Luma Studio', "Hi {$a['customer']},\n\nYour appointment is confirmed.\n{$a['service']} with {$a['stylist']}\n" . date_label($a['starts_at']) . "\n\nWe look forward to seeing you. You can manage your booking from your account.");
            flash($sent ? 'You’re booked! A confirmation has been sent to your email.' : 'You’re booked! We could not send the confirmation email, so please contact the studio to confirm your email address.', $sent ? 'success' : 'error');
            redirect('account');

        case 'cancel_appointment':
            $user = require_login();
            $q = db()->prepare("UPDATE appointments SET status='cancelled',cancelled_at=? WHERE id=? AND customer_id=? AND status='confirmed' AND starts_at>?");
            $q->execute([date('Y-m-d H:i:s'), (int)($_POST['appointment_id'] ?? 0), $user['id'], date('Y-m-d H:i:s')]);
            if (!$q->rowCount()) throw new DomainException('This appointment can no longer be cancelled online. Please call the studio.');
            flash('Your appointment has been cancelled.'); redirect('account');

        case 'reschedule':
            $user = require_login();
            $service = new BookingService(db());
            $id = $service->reschedule((int)$user['id'], (int)($_POST['appointment_id'] ?? 0), (int)($_POST['stylist_id'] ?? 0), (string)($_POST['starts_at'] ?? ''));
            flash('Your appointment has been rescheduled.'); redirect('account');

        case 'contact':
            if (!empty($_POST['website'])) throw new DomainException('Your message could not be sent.');
            if (!throttle('contact', 4, 3600)) throw new DomainException('Please wait before sending another message.');
            $name = trim((string)($_POST['name'] ?? '')); $email = trim((string)($_POST['email'] ?? '')); $message = trim((string)($_POST['message'] ?? ''));
            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 8 || mb_strlen($message) > 3000) throw new DomainException('Please add your name, a valid email and a short message.');
            global $config;
            send_email($config['mail_from'], 'Website enquiry · ' . mb_substr($name, 0, 80), "From: {$name}\nEmail: {$email}\n\n" . $message);
            flash('Thanks for reaching out. We’ll be in touch soon.'); redirect('contact');

        case 'create_staff':
            require_staff(true);
            $email = strtolower(trim((string)($_POST['email'] ?? ''))); $name = trim((string)($_POST['name'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) < 2) throw new DomainException('Enter a name and valid email address.');
            $temp = bin2hex(random_bytes(24));
            $q = db()->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'stylist')");
            try { $q->execute([$name, $email, password_hash($temp, PASSWORD_DEFAULT)]); }
            catch (PDOException $e) { if ($e->getCode() === '23000') throw new DomainException('That email already has an account.'); throw $e; }
            make_reset((int)db()->lastInsertId(), $email, $name);
            flash('Staff login created. A password setup link was sent.'); redirect('manage');

        case 'save_service':
            require_staff(true);
            $id = (int)($_POST['id'] ?? 0); $name = trim((string)($_POST['name'] ?? '')); $duration = (int)($_POST['duration'] ?? 0);
            $price = (float)($_POST['price'] ?? -1); $description = trim((string)($_POST['description'] ?? ''));
            if (mb_strlen($name) < 2 || $duration < 15 || $duration > 480 || $price < 0 || mb_strlen($description) > 1500) throw new DomainException('Check the service name, duration, price and description.');
            if ($id) $q = db()->prepare('UPDATE services SET name=?,description=?,duration_minutes=?,price_cents=? WHERE id=?');
            else $q = db()->prepare('INSERT INTO services (name,description,duration_minutes,price_cents) VALUES (?,?,?,?)');
            $args = [$name,$description,$duration,(int)round($price*100)]; if ($id) $args[] = $id; $q->execute($args);
            flash('Service saved.'); redirect('manage');

        case 'toggle_service':
            require_staff(true);
            db()->prepare('UPDATE services SET is_active=1-is_active WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            flash('Service updated.'); redirect('manage');

        case 'save_stylist':
            require_staff(true);
            $id = (int)($_POST['id'] ?? 0); $name = trim((string)($_POST['name'] ?? '')); $title = trim((string)($_POST['title'] ?? 'Stylist'));
            $bio = trim((string)($_POST['bio'] ?? '')); $userId = (int)($_POST['user_id'] ?? 0); $accent = (string)($_POST['accent'] ?? '#b9816c');
            if (mb_strlen($name) < 2 || mb_strlen($bio) > 1500 || !preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) throw new DomainException('Check the stylist name, bio and color.');
            if ($id && !$userId) { $q=db()->prepare('SELECT user_id FROM stylists WHERE id=?'); $q->execute([$id]); $userId=(int)($q->fetchColumn()?:0); }
            if ($userId) { $q = db()->prepare("SELECT 1 FROM users WHERE id=? AND role='stylist'"); $q->execute([$userId]); if (!$q->fetchColumn()) throw new DomainException('Choose a staff login.'); }
            if ($id) $q = db()->prepare('UPDATE stylists SET user_id=?,name=?,title=?,bio=?,accent=?,is_active=? WHERE id=?');
            else $q = db()->prepare('INSERT INTO stylists (user_id,name,title,bio,accent,is_active) VALUES (?,?,?,?,?,?)');
            $args = [$userId ?: null,$name,$title,$bio,$accent,isset($_POST['is_active'])?1:0]; if ($id) $args[]=$id; $q->execute($args);
            flash('Stylist profile saved.'); redirect('manage');

        case 'save_hours':
            require_staff(true);
            $stylistId = (int)($_POST['stylist_id'] ?? 0); $q = db()->prepare('SELECT 1 FROM stylists WHERE id=?'); $q->execute([$stylistId]);
            if (!$q->fetchColumn()) throw new DomainException('Choose a stylist.');
            for ($weekday=1; $weekday<=7; $weekday++) {
                $closed = !empty($_POST['closed'][$weekday]) ? 1 : 0;
                $open = (string)($_POST['open'][$weekday] ?? '09:00'); $close = (string)($_POST['close'][$weekday] ?? '17:00');
                if (!$closed && (!preg_match('/^\d{2}:\d{2}$/',$open) || !preg_match('/^\d{2}:\d{2}$/',$close) || $open >= $close)) throw new DomainException('Open and close times need to be valid, with closing later than opening.');
                $q = db()->prepare('INSERT INTO working_hours (stylist_id,weekday,opens_at,closes_at,is_closed) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE opens_at=VALUES(opens_at),closes_at=VALUES(closes_at),is_closed=VALUES(is_closed)');
                $q->execute([$stylistId,$weekday,$closed?null:$open,$closed?null:$close,$closed]);
            }
            flash('Working hours updated.'); redirect('manage');

        case 'add_block':
            require_staff(true);
            $stylistId = (int)($_POST['stylist_id'] ?? 0); $start = str_replace('T',' ',(string)($_POST['starts_at'] ?? '')); $end = str_replace('T',' ',(string)($_POST['ends_at'] ?? '')); $reason = trim((string)($_POST['reason'] ?? ''));
            $a = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $start); $b = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $end);
            if (!$a || !$b || $b <= $a || mb_strlen($reason) < 2) throw new DomainException('Choose a valid time range and add a reason.');
            if ($stylistId < 0) throw new DomainException('Invalid stylist.');
            $q = db()->prepare('INSERT INTO blocked_times (stylist_id,starts_at,ends_at,reason,created_by) VALUES (?,?,?,?,?)');
            $q->execute([$stylistId ?: null,$a->format('Y-m-d H:i:s'),$b->format('Y-m-d H:i:s'),mb_substr($reason,0,190),current_user()['id']]);
            flash('Time blocked.'); redirect('blocks');

        case 'remove_block':
            require_staff(true);
            db()->prepare('DELETE FROM blocked_times WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            flash('Block removed.'); redirect('blocks');

        case 'add_note':
            $user = require_staff(); $customerId = (int)($_POST['customer_id'] ?? 0); $note = trim((string)($_POST['note'] ?? ''));
            if (!customer_can_access($user,$customerId) || mb_strlen($note) < 2 || mb_strlen($note) > 2000) throw new DomainException('You do not have access to that customer or the note is too long.');
            db()->prepare('INSERT INTO customer_notes (customer_id,author_id,note) VALUES (?,?,?)')->execute([$customerId,$user['id'],$note]);
            flash('Customer note added.'); redirect('customers',['id'=>$customerId]);

        case 'appointment_status':
            $user = require_staff(); $appointmentId = (int)($_POST['appointment_id'] ?? 0); $status=(string)($_POST['status'] ?? '');
            if (!in_array($status,['completed','no_show'],true)) throw new DomainException('Choose a valid appointment status.');
            $sql = "UPDATE appointments a SET status=? WHERE a.id=? AND a.status='confirmed'"; $args=[$status,$appointmentId];
            if ($user['role']==='stylist') { $sql.=' AND EXISTS (SELECT 1 FROM stylists s WHERE s.id=a.stylist_id AND s.user_id=?)'; $args[]=$user['id']; }
            db()->prepare($sql)->execute($args); flash('Appointment updated.'); redirect('admin');

        default:
            throw new DomainException('That action is not available.');
    }
} catch (Throwable $e) {
    try { $connection = db(); if ($connection->inTransaction()) $connection->rollBack(); } catch (Throwable) {}
    if ($e instanceof DomainException || $e instanceof InvalidArgumentException) flash($e->getMessage(), 'error');
    else { error_log((string)$e); flash('That did not save. Please try again or contact the studio.', 'error'); }
    $back = preg_replace('/[^a-z_]/', '', (string)($_POST['return_page'] ?? ''));
    redirect($back ?: match ($action) {
        'login' => 'login', 'register' => 'register', 'forgot' => 'forgot', 'reset' => 'reset',
        'book' => 'book', 'contact' => 'contact', 'reschedule' => 'reschedule',
        'add_note' => 'customers', 'add_block','remove_block' => 'blocks', default => 'manage'
    }, isset($_POST['return_id']) ? ['id' => (int)$_POST['return_id']] : []);
}
