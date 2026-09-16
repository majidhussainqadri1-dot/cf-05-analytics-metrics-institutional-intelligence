#!/usr/bin/env python3
from pathlib import Path
p=Path('src/Domain/FutureFeatureService.php')
s=p.read_text(encoding='utf-8')
repls=[
("    public function configure(string $featureId, array $config, int $actorUserId, int $expectedRowVersion = 0): array|WP_Error\n    {\n        $definition = FutureFeatureRegistry::get($featureId);",
 "    public function configure(string $featureId, array $config, int $actorUserId, int $expectedRowVersion = 0): array|WP_Error\n    {\n        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);\n        $definition = FutureFeatureRegistry::get($featureId);"),
("    public function transition(string $featureId, string $action, string $reason, int $actorUserId, int $expectedRowVersion): array|WP_Error\n    {\n        $definition = FutureFeatureRegistry::get($featureId);",
 "    public function transition(string $featureId, string $action, string $reason, int $actorUserId, int $expectedRowVersion): array|WP_Error\n    {\n        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);\n        $definition = FutureFeatureRegistry::get($featureId);"),
("    public function run(string $featureId, array $input, int $actorUserId, bool $dryRun = false): array|WP_Error\n    {\n        $definition=FutureFeatureRegistry::get($featureId);",
 "    public function run(string $featureId, array $input, int $actorUserId, bool $dryRun = false): array|WP_Error\n    {\n        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);\n        $definition=FutureFeatureRegistry::get($featureId);"),
("    public function createIncident(array $payload,int $actorUserId):array|WP_Error\n    {\n        $summary=Text::truncate",
 "    public function createIncident(array $payload,int $actorUserId):array|WP_Error\n    {\n        if ($actorUserId < 1) return new WP_Error('smai_future_actor_required', 'An authenticated actor is required.', ['status'=>403]);\n        $summary=Text::truncate")]
for old,new in repls:
    if old not in s: raise SystemExit('missing actor-validation anchor')
    s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')
print('Review-5 service actor validation applied.')
