<?php
declare(strict_types=1);

final class BookingService
{
    public function __construct(private PDO $pdo) {}

    public function services(): array
    {
        return $this->pdo->query('SELECT * FROM services WHERE is_active=1 ORDER BY display_order,name')->fetchAll();
    }

    public function stylists(?int $serviceId = null): array
    {
        $sql = 'SELECT s.id,s.name,s.title,s.bio,s.photo_url,s.accent FROM stylists s WHERE s.is_active=1';
        if ($serviceId) $sql .= ' AND EXISTS (SELECT 1 FROM stylist_services ss WHERE ss.stylist_id=s.id AND ss.service_id=' . (int)$serviceId . ')';
        return $this->pdo->query($sql . ' ORDER BY s.id')->fetchAll();
    }

    public function slots(int $serviceId, int $stylistId, string $date): array
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) return [];
        $today = new DateTimeImmutable('today');
        if ($day < $today || $day > $today->modify('+90 days')) return [];
        $serviceQ = $this->pdo->prepare('SELECT duration_minutes FROM services WHERE id=? AND is_active=1');
        $serviceQ->execute([$serviceId]);
        $service = $serviceQ->fetch();
        if (!$service) return [];
        $sql = 'SELECT s.id,s.name,wh.opens_at,wh.closes_at FROM stylists s
          JOIN stylist_services ss ON ss.stylist_id=s.id AND ss.service_id=?
          JOIN working_hours wh ON wh.stylist_id=s.id AND wh.weekday=?
          WHERE s.is_active=1 AND wh.is_closed=0';
        $params = [$serviceId, (int)$day->format('N')];
        if ($stylistId > 0) { $sql .= ' AND s.id=?'; $params[] = $stylistId; }
        $staffQ = $this->pdo->prepare($sql . ' ORDER BY s.id');
        $staffQ->execute($params);
        $duration = (int)$service['duration_minutes'];
        $now = new DateTimeImmutable();
        $result = [];
        foreach ($staffQ->fetchAll() as $staff) {
            if (!$staff['opens_at'] || !$staff['closes_at']) continue;
            $open = new DateTimeImmutable($date . ' ' . $staff['opens_at']);
            $close = new DateTimeImmutable($date . ' ' . $staff['closes_at']);
            for ($start = $open; $start->modify('+' . $duration . ' minutes') <= $close; $start = $start->modify('+15 minutes')) {
                if ($start <= $now) continue;
                $end = $start->modify('+' . $duration . ' minutes');
                if ($this->isFree((int)$staff['id'], $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'))) {
                    $result[] = [
                        'stylist_id' => (int)$staff['id'],
                        'stylist' => $staff['name'],
                        'start' => $start->format('Y-m-d H:i:s'),
                        'label' => $start->format('g:i A') . ($stylistId < 1 ? ' · ' . $staff['name'] : ''),
                    ];
                }
            }
        }
        usort($result, static fn($a, $b) => [$a['start'], $a['stylist_id']] <=> [$b['start'], $b['stylist_id']]);
        return $result;
    }

    public function create(int $customerId, int $serviceId, int $stylistId, string $startAt, string $customerNote = ''): int
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $startAt);
        if (!$start || $start->format('Y-m-d H:i:s') !== $startAt) throw new DomainException('Choose a valid appointment time.');
        $serviceQ = $this->pdo->prepare('SELECT duration_minutes FROM services WHERE id=? AND is_active=1');
        $serviceQ->execute([$serviceId]);
        $service = $serviceQ->fetch();
        if (!$service) throw new DomainException('That service is no longer available.');
        $end = $start->modify('+' . (int)$service['duration_minutes'] . ' minutes');
        $lockName = 'luma_' . substr(hash('sha256', $stylistId . '|' . $start->format('Y-m-d')), 0, 48);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 8)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new DomainException('That time is busy. Please choose another slot.');
        try {
            $this->pdo->beginTransaction();
            $choices = $this->slots($serviceId, $stylistId, $start->format('Y-m-d'));
            $valid = false;
            foreach ($choices as $choice) if ($choice['stylist_id'] === $stylistId && $choice['start'] === $startAt) { $valid = true; break; }
            if (!$valid) throw new DomainException('That time was just booked or is no longer available. Please choose another slot.');
            $insert = $this->pdo->prepare("INSERT INTO appointments (customer_id,stylist_id,service_id,starts_at,ends_at,status,customer_note) VALUES (?,?,?,?,?,'confirmed',?)");
            $insert->execute([$customerId, $stylistId, $serviceId, $startAt, $end->format('Y-m-d H:i:s'), mb_substr(trim($customerNote), 0, 1000)]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    public function reschedule(int $customerId, int $appointmentId, int $stylistId, string $startAt): int
    {
        $lookup = $this->pdo->prepare("SELECT * FROM appointments WHERE id=? AND customer_id=? AND status='confirmed' AND starts_at>?");
        $lookup->execute([$appointmentId, $customerId, date('Y-m-d H:i:s')]);
        $current = $lookup->fetch();
        if (!$current) throw new DomainException('That appointment can no longer be changed.');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $startAt);
        if (!$start || $start->format('Y-m-d H:i:s') !== $startAt) throw new DomainException('Choose a valid appointment time.');
        $lockNames = array_unique([
            'luma_' . substr(hash('sha256', $current['stylist_id'] . '|' . substr($current['starts_at'], 0, 10)), 0, 48),
            'luma_' . substr(hash('sha256', $stylistId . '|' . $start->format('Y-m-d')), 0, 48),
        ]);
        sort($lockNames);
        $held = [];
        try {
            foreach ($lockNames as $name) {
                $q = $this->pdo->prepare('SELECT GET_LOCK(?, 8)');
                $q->execute([$name]);
                if ((int)$q->fetchColumn() !== 1) throw new DomainException('That time is busy. Please choose another slot.');
                $held[] = $name;
            }
            $this->pdo->beginTransaction();
            $lookup = $this->pdo->prepare("SELECT * FROM appointments WHERE id=? AND customer_id=? AND status='confirmed' AND starts_at>? FOR UPDATE");
            $lookup->execute([$appointmentId, $customerId, date('Y-m-d H:i:s')]);
            $current = $lookup->fetch();
            if (!$current) throw new DomainException('That appointment can no longer be changed.');
            $cancel = $this->pdo->prepare("UPDATE appointments SET status='cancelled',cancelled_at=? WHERE id=?");
            $cancel->execute([date('Y-m-d H:i:s'), $appointmentId]);
            $choices = $this->slots((int)$current['service_id'], $stylistId, $start->format('Y-m-d'));
            $valid = false;
            foreach ($choices as $choice) if ($choice['stylist_id'] === $stylistId && $choice['start'] === $startAt) { $valid = true; break; }
            if (!$valid) throw new DomainException('That time was just booked or is no longer available. Please choose another slot.');
            $serviceQ = $this->pdo->prepare('SELECT duration_minutes FROM services WHERE id=?');
            $serviceQ->execute([$current['service_id']]);
            $duration = (int)$serviceQ->fetchColumn();
            $end = $start->modify('+' . $duration . ' minutes');
            $insert = $this->pdo->prepare("INSERT INTO appointments (customer_id,stylist_id,service_id,starts_at,ends_at,status,customer_note) VALUES (?,?,?,?,?,'confirmed',?)");
            $insert->execute([$customerId, $stylistId, $current['service_id'], $startAt, $end->format('Y-m-d H:i:s'), $current['customer_note']]);
            $newId = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        } finally {
            foreach ($held as $name) {
                $q = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $q->execute([$name]);
            }
        }
    }

    private function isFree(int $stylistId, string $start, string $end): bool
    {
        $q = $this->pdo->prepare("SELECT 1 FROM appointments WHERE stylist_id=? AND status='confirmed' AND starts_at<? AND ends_at>? LIMIT 1");
        $q->execute([$stylistId, $end, $start]);
        if ($q->fetchColumn()) return false;
        $q = $this->pdo->prepare('SELECT 1 FROM blocked_times WHERE (stylist_id=? OR stylist_id IS NULL) AND starts_at<? AND ends_at>? LIMIT 1');
        $q->execute([$stylistId, $end, $start]);
        if ($q->fetchColumn()) return false;
        $q = $this->pdo->prepare('SELECT 1 FROM time_off WHERE (stylist_id=? OR stylist_id IS NULL) AND starts_at<? AND ends_at>? LIMIT 1');
        $q->execute([$stylistId, $end, $start]);
        return !$q->fetchColumn();
    }
}
