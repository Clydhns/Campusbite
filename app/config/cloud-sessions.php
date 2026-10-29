<?php
final class CampusBiteSessions implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private PDO $pdo;
    private string $secret;
    private bool $locked = false;
    public function __construct(PDO $pdo, string $secret) { $this->pdo=$pdo; $this->secret=$secret; }
    private function key(string $id): string { return hash_hmac('sha256',$id,$this->secret); }
    private function lock(string $id): void {
        if ($this->locked) return;
        // A dedicated session-pooled connection owns this lock until write/close.
        // Do not hold a SQL transaction open while the page uses its own connection.
        $stmt=$this->pdo->prepare('SELECT pg_advisory_lock(hashtextextended(?,0))');
        $stmt->execute([$this->key($id)]);
        $this->lockedKey=$this->key($id);
        $this->locked=true;
    }
    private ?string $lockedKey = null;
    private function commit(): void {
        if ($this->lockedKey!==null) {
            $stmt=$this->pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(?,0))');
            $stmt->execute([$this->lockedKey]);
        }
        $this->lockedKey=null;
        $this->locked=false;
    }
    public function open(string $path,string $name): bool { return true; }
    public function close(): bool {
        $this->commit();
        return true;
    }
    public function read(string $id): string|false {
        $this->lock($id);
        $stmt=$this->pdo->prepare('SELECT payload FROM campusbite_private.sessions WHERE session_key=? AND expires_at>CURRENT_TIMESTAMP');
        $stmt->execute([$this->key($id)]);
        $payload=$stmt->fetchColumn();
        if ($payload===false) return '';
        $decoded=base64_decode($payload,true);
        return $decoded===false ? '' : $decoded;
    }
    public function write(string $id,string $data): bool {
        $this->lock($id);
        $stmt=$this->pdo->prepare("INSERT INTO campusbite_private.sessions(session_key,payload,expires_at) VALUES (?,?,CURRENT_TIMESTAMP+INTERVAL '4 hours') ON CONFLICT(session_key) DO UPDATE SET payload=EXCLUDED.payload,expires_at=EXCLUDED.expires_at");
        $stmt->execute([$this->key($id),base64_encode($data)]);
        $this->commit();
        return true;
    }
    public function destroy(string $id): bool {
        $this->lock($id);
        $stmt=$this->pdo->prepare('DELETE FROM campusbite_private.sessions WHERE session_key=?');
        $stmt->execute([$this->key($id)]);
        $this->commit();
        return true;
    }
    public function gc(int $max_lifetime): int|false {
        // PHP calls GC after read: avoid deleting other sessions while holding its lock.
        if ($this->locked) return 0;
        return $this->pdo->exec('DELETE FROM campusbite_private.sessions WHERE expires_at<=CURRENT_TIMESTAMP');
    }
    public function validateId(string $id): bool {
        $stmt=$this->pdo->prepare('SELECT 1 FROM campusbite_private.sessions WHERE session_key=? AND expires_at>CURRENT_TIMESTAMP');
        $stmt->execute([$this->key($id)]);
        return (bool)$stmt->fetchColumn();
    }
    public function updateTimestamp(string $id,string $data): bool { return $this->write($id,$data); }
}
