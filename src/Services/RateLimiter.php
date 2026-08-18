<?php
declare(strict_types=1);
namespace Services;
use Core\Database;
final class RateLimiter {
 private \PDO $db; private int $max; private int $window;
  public function __construct(){ $this->db=Database::getInstance();$this->max=max(1,(int)(\configValue('RATE_LIMIT_MAX_REQUESTS','20')??20));$this->window=max(1,(int)(\configValue('RATE_LIMIT_WINDOW','60')??60)); }
 public function check(int $userId): bool { $cutoff=(new \DateTimeImmutable('now'))->modify('-'.$this->window.' seconds')->format('Y-m-d H:i:s');$s=$this->db->prepare('SELECT COUNT(*) FROM rate_limits WHERE user_id=? AND created_at>=?');$s->execute([$userId,$cutoff]);if((int)$s->fetchColumn()>=$this->max)return false;$this->db->prepare('INSERT INTO rate_limits(user_id) VALUES(?)')->execute([$userId]);$this->db->prepare('DELETE FROM rate_limits WHERE created_at<?')->execute([$cutoff]);return true; }
}
