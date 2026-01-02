# PostgreSQL Query Optimization: Puzzle Search with Solving Statistics

## Problem Summary

A search query on `myspeedpuzzling.com` takes ~2 seconds due to aggregating 270k `puzzle_solving_time` rows for sorting by `solved_times`.

### Table Sizes
- `puzzle`: 25k rows
- `puzzle_solving_time`: 270k rows  
- `manufacturer`: 1k rows

### Core Issue
The query must aggregate ALL matching puzzles to sort by `solved_times` before applying LIMIT. Additionally, `json_array_length(team->'puzzlers')` is computed repeatedly on 270k rows.

---

## Solution Overview

1. **Add `players_count` + `puzzling_type` to `puzzle_solving_time`** - Avoid JSON parsing
2. **Create separate `PuzzleStatistics` entity** - Denormalized stats in dedicated table
3. **Domain events + hourly cron** - Keep stats in sync
4. **Trigram indexes** - Fast ILIKE searches (no custom Postgres functions needed)

---

## Step 1: Extend `puzzle_solving_time` Table

### 1.1 Database Migration

```sql
-- Add players_count and puzzling_type columns
ALTER TABLE puzzle_solving_time
    ADD COLUMN players_count smallint NOT NULL DEFAULT 1,
    ADD COLUMN puzzling_type varchar(10) NOT NULL DEFAULT 'solo';

-- Populate from existing data
UPDATE puzzle_solving_time
SET 
    players_count = CASE 
        WHEN team IS NULL THEN 1
        ELSE json_array_length(team->'puzzlers')
    END,
    puzzling_type = CASE 
        WHEN team IS NULL THEN 'solo'
        WHEN json_array_length(team->'puzzlers') = 2 THEN 'duo'
        ELSE 'team'
    END;

-- Index for filtering
CREATE INDEX custom_pst_puzzling_type ON puzzle_solving_time (puzzling_type);
CREATE INDEX custom_pst_puzzle_type ON puzzle_solving_time (puzzle_id, puzzling_type);
```

### 1.2 PuzzlingType Enum

```php
namespace App\Enum;

enum PuzzlingType: string
{
    case Solo = 'solo';
    case Duo = 'duo';
    case Team = 'team';

    public static function fromPlayersCount(int $count): self
    {
        return match (true) {
            $count === 1 => self::Solo,
            $count === 2 => self::Duo,
            default => self::Team,
        };
    }
}
```

### 1.3 Updated PuzzleSolvingTime Entity

```php
namespace App\Entity;

use App\Enum\PuzzlingType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'puzzle_solving_time')]
class PuzzleSolvingTime
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Puzzle::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Puzzle $puzzle;

    #[ORM\Column]
    public int $secondsToSolve;

    #[ORM\Column(type: PuzzlersGroupDoctrineType::NAME, nullable: true)]
    public null|PuzzlersGroup $team;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Positive]
    public int $playersCount;

    #[ORM\Column(type: 'string', enumType: PuzzlingType::class)]
    public PuzzlingType $puzzlingType;

    public function __construct(
        Puzzle $puzzle,
        int $secondsToSolve,
        null|PuzzlersGroup $team,
    ) {
        $this->puzzle = $puzzle;
        $this->secondsToSolve = $secondsToSolve;
        $this->team = $team;
        $this->playersCount = $team === null ? 1 : count($team->puzzlers);
        $this->puzzlingType = PuzzlingType::fromPlayersCount($this->playersCount);
    }
}
```

---

## Step 2: Create `PuzzleStatistics` Entity

### 2.1 Database Migration

```sql
CREATE TABLE puzzle_statistics (
    puzzle_id int PRIMARY KEY REFERENCES puzzle(id) ON DELETE CASCADE,
    
    -- Total
    solved_times_count int NOT NULL DEFAULT 0,
    fastest_time int DEFAULT NULL,
    average_time int DEFAULT NULL,
    slowest_time int DEFAULT NULL,
    
    -- Solo
    solved_times_solo_count int NOT NULL DEFAULT 0,
    fastest_time_solo int DEFAULT NULL,
    average_time_solo int DEFAULT NULL,
    slowest_time_solo int DEFAULT NULL,
    
    -- Duo
    solved_times_duo_count int NOT NULL DEFAULT 0,
    fastest_time_duo int DEFAULT NULL,
    average_time_duo int DEFAULT NULL,
    slowest_time_duo int DEFAULT NULL,
    
    -- Team
    solved_times_team_count int NOT NULL DEFAULT 0,
    fastest_time_team int DEFAULT NULL,
    average_time_team int DEFAULT NULL,
    slowest_time_team int DEFAULT NULL
);

-- Indexes for sorting
CREATE INDEX idx_ps_solved_count ON puzzle_statistics (solved_times_count);
CREATE INDEX idx_ps_solved_solo_count ON puzzle_statistics (solved_times_solo_count);
CREATE INDEX idx_ps_solved_duo_count ON puzzle_statistics (solved_times_duo_count);
CREATE INDEX idx_ps_solved_team_count ON puzzle_statistics (solved_times_team_count);
CREATE INDEX idx_ps_fastest_time ON puzzle_statistics (fastest_time);
CREATE INDEX idx_ps_fastest_time_solo ON puzzle_statistics (fastest_time_solo);
```

### 2.2 PuzzleStatistics Entity

```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints\Immutable;

#[ORM\Entity]
#[ORM\Table(name: 'puzzle_statistics')]
class PuzzleStatistics
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Puzzle::class)]
    #[ORM\JoinColumn(name: 'puzzle_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Immutable]
    public Puzzle $puzzle;

    // Total
    #[ORM\Column(options: ['default' => 0])]
    public int $solvedTimesCount = 0;

    #[ORM\Column(nullable: true)]
    public ?int $fastestTime = null;

    #[ORM\Column(nullable: true)]
    public ?int $averageTime = null;

    #[ORM\Column(nullable: true)]
    public ?int $slowestTime = null;

    // Solo
    #[ORM\Column(options: ['default' => 0])]
    public int $solvedTimesSoloCount = 0;

    #[ORM\Column(nullable: true)]
    public ?int $fastestTimeSolo = null;

    #[ORM\Column(nullable: true)]
    public ?int $averageTimeSolo = null;

    #[ORM\Column(nullable: true)]
    public ?int $slowestTimeSolo = null;

    // Duo
    #[ORM\Column(options: ['default' => 0])]
    public int $solvedTimesDuoCount = 0;

    #[ORM\Column(nullable: true)]
    public ?int $fastestTimeDuo = null;

    #[ORM\Column(nullable: true)]
    public ?int $averageTimeDuo = null;

    #[ORM\Column(nullable: true)]
    public ?int $slowestTimeDuo = null;

    // Team
    #[ORM\Column(options: ['default' => 0])]
    public int $solvedTimesTeamCount = 0;

    #[ORM\Column(nullable: true)]
    public ?int $fastestTimeTeam = null;

    #[ORM\Column(nullable: true)]
    public ?int $averageTimeTeam = null;

    #[ORM\Column(nullable: true)]
    public ?int $slowestTimeTeam = null;

    public function __construct(Puzzle $puzzle)
    {
        $this->puzzle = $puzzle;
    }

    public function update(PuzzleStatisticsData $data): void
    {
        $this->solvedTimesCount = $data->totalCount;
        $this->fastestTime = $data->fastestTime;
        $this->averageTime = $data->averageTime;
        $this->slowestTime = $data->slowestTime;

        $this->solvedTimesSoloCount = $data->soloCount;
        $this->fastestTimeSolo = $data->fastestTimeSolo;
        $this->averageTimeSolo = $data->averageTimeSolo;
        $this->slowestTimeSolo = $data->slowestTimeSolo;

        $this->solvedTimesDuoCount = $data->duoCount;
        $this->fastestTimeDuo = $data->fastestTimeDuo;
        $this->averageTimeDuo = $data->averageTimeDuo;
        $this->slowestTimeDuo = $data->slowestTimeDuo;

        $this->solvedTimesTeamCount = $data->teamCount;
        $this->fastestTimeTeam = $data->fastestTimeTeam;
        $this->averageTimeTeam = $data->averageTimeTeam;
        $this->slowestTimeTeam = $data->slowestTimeTeam;
    }
}
```

### 2.3 PuzzleStatisticsData DTO

```php
namespace App\DTO;

readonly class PuzzleStatisticsData
{
    public function __construct(
        public int $totalCount = 0,
        public ?int $fastestTime = null,
        public ?int $averageTime = null,
        public ?int $slowestTime = null,

        public int $soloCount = 0,
        public ?int $fastestTimeSolo = null,
        public ?int $averageTimeSolo = null,
        public ?int $slowestTimeSolo = null,

        public int $duoCount = 0,
        public ?int $fastestTimeDuo = null,
        public ?int $averageTimeDuo = null,
        public ?int $slowestTimeDuo = null,

        public int $teamCount = 0,
        public ?int $fastestTimeTeam = null,
        public ?int $averageTimeTeam = null,
        public ?int $slowestTimeTeam = null,
    ) {}

    public static function empty(): self
    {
        return new self();
    }
}
```

### 2.4 Add Relation to Puzzle Entity

```php
// In Puzzle entity, add:

#[ORM\OneToOne(targetEntity: PuzzleStatistics::class, mappedBy: 'puzzle', cascade: ['persist', 'remove'])]
public ?PuzzleStatistics $statistics = null;
```

---

## Step 3: Statistics Calculator Service

```php
namespace App\Service;

use App\DTO\PuzzleStatisticsData;
use Doctrine\DBAL\Connection;

class PuzzleStatisticsCalculator
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function calculateForPuzzle(int $puzzleId): PuzzleStatisticsData
    {
        $result = $this->connection->executeQuery("
            SELECT
                -- Total
                COUNT(*) AS total_count,
                MIN(seconds_to_solve) AS fastest_time,
                AVG(seconds_to_solve)::int AS average_time,
                MAX(seconds_to_solve) AS slowest_time,

                -- Solo (using new column!)
                COUNT(*) FILTER (WHERE puzzling_type = 'solo') AS solo_count,
                MIN(seconds_to_solve) FILTER (WHERE puzzling_type = 'solo') AS fastest_time_solo,
                AVG(seconds_to_solve)::int FILTER (WHERE puzzling_type = 'solo') AS average_time_solo,
                MAX(seconds_to_solve) FILTER (WHERE puzzling_type = 'solo') AS slowest_time_solo,

                -- Duo
                COUNT(*) FILTER (WHERE puzzling_type = 'duo') AS duo_count,
                MIN(seconds_to_solve) FILTER (WHERE puzzling_type = 'duo') AS fastest_time_duo,
                AVG(seconds_to_solve)::int FILTER (WHERE puzzling_type = 'duo') AS average_time_duo,
                MAX(seconds_to_solve) FILTER (WHERE puzzling_type = 'duo') AS slowest_time_duo,

                -- Team
                COUNT(*) FILTER (WHERE puzzling_type = 'team') AS team_count,
                MIN(seconds_to_solve) FILTER (WHERE puzzling_type = 'team') AS fastest_time_team,
                AVG(seconds_to_solve)::int FILTER (WHERE puzzling_type = 'team') AS average_time_team,
                MAX(seconds_to_solve) FILTER (WHERE puzzling_type = 'team') AS slowest_time_team
            FROM puzzle_solving_time
            WHERE puzzle_id = :puzzleId
        ", ['puzzleId' => $puzzleId])->fetchAssociative();

        if ($result === false || (int) $result['total_count'] === 0) {
            return PuzzleStatisticsData::empty();
        }

        return new PuzzleStatisticsData(
            totalCount: (int) $result['total_count'],
            fastestTime: $result['fastest_time'],
            averageTime: $result['average_time'],
            slowestTime: $result['slowest_time'],

            soloCount: (int) $result['solo_count'],
            fastestTimeSolo: $result['fastest_time_solo'],
            averageTimeSolo: $result['average_time_solo'],
            slowestTimeSolo: $result['slowest_time_solo'],

            duoCount: (int) $result['duo_count'],
            fastestTimeDuo: $result['fastest_time_duo'],
            averageTimeDuo: $result['average_time_duo'],
            slowestTimeDuo: $result['slowest_time_duo'],

            teamCount: (int) $result['team_count'],
            fastestTimeTeam: $result['fastest_time_team'],
            averageTimeTeam: $result['average_time_team'],
            slowestTimeTeam: $result['slowest_time_team'],
        );
    }
}
```

---

## Step 4: Domain Events + Subscriber

### 4.1 Events

```php
namespace App\Event;

readonly class PuzzleSolvingTimeCreated
{
    public function __construct(
        public int $puzzleId,
    ) {}
}

readonly class PuzzleSolvingTimeDeleted
{
    public function __construct(
        public int $puzzleId,
    ) {}
}

readonly class PuzzleSolvingTimeUpdated
{
    public function __construct(
        public int $puzzleId,
    ) {}
}
```

### 4.2 Event Subscriber

```php
namespace App\EventSubscriber;

use App\Entity\PuzzleStatistics;
use App\Event\PuzzleSolvingTimeCreated;
use App\Event\PuzzleSolvingTimeDeleted;
use App\Event\PuzzleSolvingTimeUpdated;
use App\Repository\PuzzleRepository;
use App\Repository\PuzzleStatisticsRepository;
use App\Service\PuzzleStatisticsCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class RecalculatePuzzleStatisticsOnSolvingTimeChange
{
    public function __construct(
        private PuzzleRepository $puzzles,
        private PuzzleStatisticsRepository $statisticsRepository,
        private PuzzleStatisticsCalculator $calculator,
        private EntityManagerInterface $em,
    ) {}

    #[AsEventListener(PuzzleSolvingTimeCreated::class)]
    #[AsEventListener(PuzzleSolvingTimeDeleted::class)]
    #[AsEventListener(PuzzleSolvingTimeUpdated::class)]
    public function __invoke(
        PuzzleSolvingTimeCreated|PuzzleSolvingTimeDeleted|PuzzleSolvingTimeUpdated $event
    ): void {
        $puzzle = $this->puzzles->find($event->puzzleId);

        if ($puzzle === null) {
            return;
        }

        $statistics = $this->statisticsRepository->findByPuzzle($puzzle);
        
        if ($statistics === null) {
            $statistics = new PuzzleStatistics($puzzle);
            $this->em->persist($statistics);
        }

        $data = $this->calculator->calculateForPuzzle($event->puzzleId);
        $statistics->update($data);

        $this->em->flush();
    }
}
```

---

## Step 5: Cron Command (Hourly Full Recalculation)

```php
namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:recalculate-puzzle-statistics',
    description: 'Recalculates all puzzle statistics',
)]
class RecalculatePuzzleStatisticsCommand extends Command
{
    public function __construct(
        private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->info('Recalculating puzzle statistics...');

        // Upsert all statistics using INSERT ... ON CONFLICT
        $affected = $this->connection->executeStatement("
            INSERT INTO puzzle_statistics (
                puzzle_id,
                solved_times_count, fastest_time, average_time, slowest_time,
                solved_times_solo_count, fastest_time_solo, average_time_solo, slowest_time_solo,
                solved_times_duo_count, fastest_time_duo, average_time_duo, slowest_time_duo,
                solved_times_team_count, fastest_time_team, average_time_team, slowest_time_team
            )
            SELECT
                puzzle_id,
                
                COUNT(*),
                MIN(seconds_to_solve),
                AVG(seconds_to_solve)::int,
                MAX(seconds_to_solve),

                COUNT(*) FILTER (WHERE puzzling_type = 'solo'),
                MIN(seconds_to_solve) FILTER (WHERE puzzling_type = 'solo'),
                AVG(seconds_to_solve)::int FILTER (WHERE puzzling_type = 'solo'),
                MAX(seconds_to_solve) FILTER (WHERE puzzling_type = 'solo'),

                COUNT(*) FILTER (WHERE puzzling_type = 'duo'),
                MIN(seconds_to_solve) FILTER (WHERE puzzling_type = 'duo'),
                AVG(seconds_to_solve)::int FILTER (WHERE puzzling_type = 'duo'),
                MAX(seconds_to_solve) FILTER (WHERE puzzling_type = 'duo'),

                COUNT(*) FILTER (WHERE puzzling_type = 'team'),
                MIN(seconds_to_solve) FILTER (WHERE puzzling_type = 'team'),
                AVG(seconds_to_solve)::int FILTER (WHERE puzzling_type = 'team'),
                MAX(seconds_to_solve) FILTER (WHERE puzzling_type = 'team')
            FROM puzzle_solving_time
            GROUP BY puzzle_id
            ON CONFLICT (puzzle_id) DO UPDATE SET
                solved_times_count = EXCLUDED.solved_times_count,
                fastest_time = EXCLUDED.fastest_time,
                average_time = EXCLUDED.average_time,
                slowest_time = EXCLUDED.slowest_time,
                
                solved_times_solo_count = EXCLUDED.solved_times_solo_count,
                fastest_time_solo = EXCLUDED.fastest_time_solo,
                average_time_solo = EXCLUDED.average_time_solo,
                slowest_time_solo = EXCLUDED.slowest_time_solo,
                
                solved_times_duo_count = EXCLUDED.solved_times_duo_count,
                fastest_time_duo = EXCLUDED.fastest_time_duo,
                average_time_duo = EXCLUDED.average_time_duo,
                slowest_time_duo = EXCLUDED.slowest_time_duo,
                
                solved_times_team_count = EXCLUDED.solved_times_team_count,
                fastest_time_team = EXCLUDED.fastest_time_team,
                average_time_team = EXCLUDED.average_time_team,
                slowest_time_team = EXCLUDED.slowest_time_team
        ");

        // Reset statistics for puzzles with no solving times
        $this->connection->executeStatement("
            UPDATE puzzle_statistics ps
            SET
                solved_times_count = 0,
                fastest_time = NULL,
                average_time = NULL,
                slowest_time = NULL,
                solved_times_solo_count = 0,
                fastest_time_solo = NULL,
                average_time_solo = NULL,
                slowest_time_solo = NULL,
                solved_times_duo_count = 0,
                fastest_time_duo = NULL,
                average_time_duo = NULL,
                slowest_time_duo = NULL,
                solved_times_team_count = 0,
                fastest_time_team = NULL,
                average_time_team = NULL,
                slowest_time_team = NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM puzzle_solving_time pst WHERE pst.puzzle_id = ps.puzzle_id
            )
            AND ps.solved_times_count > 0
        ");

        $io->success("Processed $affected puzzle statistics");

        return Command::SUCCESS;
    }
}
```

### Crontab Entry

```cron
# Recalculate puzzle statistics every hour
0 * * * * cd /path/to/project && php bin/console app:recalculate-puzzle-statistics --env=prod >> /var/log/puzzle-stats.log 2>&1
```

---

## Step 6: Initial Data Population

Run once after migrations:

```sql
-- 1. First populate players_count and puzzling_type on puzzle_solving_time
UPDATE puzzle_solving_time
SET 
    players_count = CASE 
        WHEN team IS NULL THEN 1
        ELSE json_array_length(team->'puzzlers')
    END,
    puzzling_type = CASE 
        WHEN team IS NULL THEN 'solo'
        WHEN json_array_length(team->'puzzlers') = 2 THEN 'duo'
        ELSE 'team'
    END
WHERE players_count = 1 AND team IS NOT NULL;  -- Only update rows that need it

-- 2. Then run the cron command to populate puzzle_statistics
-- php bin/console app:recalculate-puzzle-statistics
```

---

## Step 7: Update Search Queries

### 7.1 Optimized Search Query

```php
namespace App\Repository;

use Doctrine\DBAL\Connection;

class PuzzleSearchRepository
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function search(PuzzleSearchCriteria $criteria): array
    {
        $sortClause = $this->getSortClause($criteria->sort);

        return $this->connection->executeQuery("
            SELECT 
                p.id AS puzzle_id,
                p.name AS puzzle_name,
                p.image AS puzzle_image,
                p.alternative_name AS puzzle_alternative_name,
                p.pieces_count,
                p.is_available,
                p.approved AS puzzle_approved,
                p.ean AS puzzle_ean,
                p.identification_number AS puzzle_identification_number,

                m.name AS manufacturer_name,
                m.id AS manufacturer_id,

                -- Statistics from dedicated table
                COALESCE(ps.solved_times_count, 0) AS solved_times_count,
                ps.fastest_time,
                ps.average_time,
                ps.slowest_time,

                COALESCE(ps.solved_times_solo_count, 0) AS solved_times_solo_count,
                ps.fastest_time_solo,
                ps.average_time_solo,
                ps.slowest_time_solo,

                COALESCE(ps.solved_times_duo_count, 0) AS solved_times_duo_count,
                ps.fastest_time_duo,
                ps.average_time_duo,
                ps.slowest_time_duo,

                COALESCE(ps.solved_times_team_count, 0) AS solved_times_team_count,
                ps.fastest_time_team,
                ps.average_time_team,
                ps.slowest_time_team,

                -- Match score
                CASE 
                    WHEN p.alternative_name ILIKE :exactSearch OR p.name ILIKE :exactSearch 
                         OR p.identification_number = :exact OR p.ean = :exact THEN 7
                    WHEN p.identification_number LIKE :prefixSearch OR p.ean LIKE :prefixSearch THEN 5
                    WHEN p.name ILIKE :containsSearch OR p.alternative_name ILIKE :containsSearch THEN 4
                    ELSE 0
                END AS match_score
            FROM puzzle p
            JOIN manufacturer m ON m.id = p.manufacturer_id
            LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
            WHERE 
                (:manufacturer::int IS NULL OR p.manufacturer_id = :manufacturer)
                AND (:minPieces::int IS NULL OR p.pieces_count >= :minPieces)
                AND (:maxPieces::int IS NULL OR p.pieces_count <= :maxPieces)
                AND (
                    p.name ILIKE :containsSearch 
                    OR p.alternative_name ILIKE :containsSearch
                    OR p.identification_number LIKE :prefixSearch
                    OR p.ean LIKE :prefixSearch
                )
            ORDER BY {$sortClause}, match_score DESC, p.name ASC
            LIMIT :limit OFFSET :offset
        ", [
            'manufacturer' => $criteria->manufacturerId,
            'minPieces' => $criteria->minPieces,
            'maxPieces' => $criteria->maxPieces,
            'exact' => $criteria->search,
            'exactSearch' => $criteria->search,
            'prefixSearch' => $criteria->search . '%',
            'containsSearch' => '%' . $criteria->search . '%',
            'limit' => $criteria->limit,
            'offset' => $criteria->offset,
        ])->fetchAllAssociative();
    }

    private function getSortClause(string $sortOption): string
    {
        return match ($sortOption) {
            'solved_times_asc' => 'COALESCE(ps.solved_times_count, 0) ASC',
            'solved_times_desc' => 'COALESCE(ps.solved_times_count, 0) DESC',
            'solved_times_solo_asc' => 'COALESCE(ps.solved_times_solo_count, 0) ASC',
            'solved_times_solo_desc' => 'COALESCE(ps.solved_times_solo_count, 0) DESC',
            'solved_times_duo_asc' => 'COALESCE(ps.solved_times_duo_count, 0) ASC',
            'solved_times_duo_desc' => 'COALESCE(ps.solved_times_duo_count, 0) DESC',
            'solved_times_team_asc' => 'COALESCE(ps.solved_times_team_count, 0) ASC',
            'solved_times_team_desc' => 'COALESCE(ps.solved_times_team_count, 0) DESC',
            'fastest_time_asc' => 'ps.fastest_time ASC NULLS LAST',
            'fastest_time_desc' => 'ps.fastest_time DESC NULLS LAST',
            'fastest_time_solo_asc' => 'ps.fastest_time_solo ASC NULLS LAST',
            'fastest_time_solo_desc' => 'ps.fastest_time_solo DESC NULLS LAST',
            'name_asc' => 'p.name ASC',
            'name_desc' => 'p.name DESC',
            'pieces_asc' => 'p.pieces_count ASC',
            'pieces_desc' => 'p.pieces_count DESC',
            default => 'COALESCE(ps.solved_times_count, 0) ASC',
        };
    }
}
```

---

## Additional Optimizations

### Trigram Indexes for ILIKE Searches

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE INDEX idx_puzzle_name_trgm ON puzzle USING gin (name gin_trgm_ops);
CREATE INDEX idx_puzzle_altname_trgm ON puzzle USING gin (alternative_name gin_trgm_ops);
```

### For Accented Search (Application-side normalization)

Instead of custom Postgres functions, normalize in PHP:

```php
// In your search service/controller
$searchNormalized = transliterator_transliterate(
    'Any-Latin; Latin-ASCII; Lower()',
    $search
);
```

### Essential Supporting Indexes

```sql
-- For puzzle filtering
CREATE INDEX idx_puzzle_manufacturer_pieces ON puzzle (manufacturer_id, pieces_count);
CREATE INDEX idx_puzzle_ean ON puzzle (ean) WHERE ean IS NOT NULL;
CREATE INDEX idx_puzzle_identification ON puzzle (identification_number) WHERE identification_number IS NOT NULL;

-- For puzzle_solving_time
CREATE INDEX custom_pst_puzzle_id ON puzzle_solving_time (puzzle_id);
```

---

## Expected Performance Impact

| Scenario | Before | After |
|----------|--------|-------|
| Sort by solved_times | ~2000ms | **~30-50ms** |
| Sort by fastest_time | ~2000ms | **~30-50ms** |
| Statistics calculation (single puzzle) | N/A | **~5ms** (no JSON parsing) |
| Full recalculation (25k puzzles) | N/A | **~2-5s** |

---

## Architecture Summary

```
┌─────────────────────────────────────────────────────────────┐
│                      puzzle_solving_time                     │
│  + players_count (smallint)                                  │
│  + puzzling_type (enum: solo/duo/team)                      │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ Domain Event
                              ▼
┌─────────────────────────────────────────────────────────────┐
│              RecalculatePuzzleStatisticsSubscriber           │
│                              │                               │
│                              ▼                               │
│                PuzzleStatisticsCalculator                    │
│                  (uses puzzling_type)                        │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                     puzzle_statistics                        │
│  (separate table, 1:1 with puzzle)                          │
│  - All 16 stats columns                                      │
│  - Indexed for sorting                                       │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ LEFT JOIN
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                      Search Query                            │
│  - No aggregation needed                                     │
│  - Simple column access                                      │
│  - Fast sorting on indexed columns                           │
└─────────────────────────────────────────────────────────────┘
```

---

## Migration Checklist

### Step 1: Infrastructure (Deploy without query changes)
- [ ] Add `players_count` and `puzzling_type` columns to `puzzle_solving_time`
- [ ] Populate existing rows with correct values
- [ ] Add index on `puzzling_type`
- [ ] Update `PuzzleSolvingTime` entity to set new columns on construct
- [ ] Create `PuzzlingType` enum
- [ ] Create `puzzle_statistics` table
- [ ] Add indexes on `puzzle_statistics` sortable columns
- [ ] Create `PuzzleStatistics` entity
- [ ] Create `PuzzleStatisticsData` DTO
- [ ] Create `PuzzleStatisticsCalculator` service
- [ ] Create domain events
- [ ] Create event subscriber
- [ ] Create cron command
- [ ] Run initial statistics population
- [ ] Set up crontab entry
- [ ] **Test:** Add solving time → verify statistics update
- [ ] **Test:** Run cron → verify all statistics updated

### Step 2: Query Migration
- [ ] Update search repository to use `puzzle_statistics` table
- [ ] Remove old aggregation queries
- [ ] Add trigram indexes
- [ ] Run `ANALYZE puzzle; ANALYZE puzzle_statistics; ANALYZE puzzle_solving_time;`
- [ ] **Benchmark:** Verify ~50x performance improvement
