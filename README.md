# Draft Signal Algorithm

This repository contains the algorithms used by Draft Signal to evaluate NFL draft picks. The primary purpose is transparency—showing how bust scores, steal scores, and other metrics are calculated.

## Calculators

### Bust Calculator

The bust calculator evaluates whether a draft pick failed to meet expectations based on their draft position. It considers:

- **Approximate Value (AV)** - Pro Football Reference's single-number metric for player value
- **Games played/started** - Availability and role
- **Snap counts** - Regular (offense + defense) and special teams snaps
- **Snap percentages** - Usage rate when active
- **Career length** - Seasons played with drafting team

### Steal Calculator

The steal calculator identifies players who significantly exceeded expectations for their draft position. It considers:

- **AV over expectation** - How much a player's production exceeded tier expectations (1x-4x mapping)
- **Usage over expectation** - Snap counts and percentages above baseline
- **Awards** - Pro Bowls, All-Pro selections, MVP, OPOY/DPOY, ROY (with late-round multiplier for bias correction)
- **Starter factor** - Games started vs. games played ratio
- **Longevity factor** - Sustained production over expected seasons

Auto-steal logic: Players drafted in round 4+ with elite accolades (2+ AP1 or 4+ Pro Bowls) are automatically classified as steals.

### Tier System

Players are grouped into tiers (A-O, plus UDFA) based on draft position:

| Tier | Pick Range | Description |
|------|------------|-------------|
| A | 1 | First overall |
| B | 2-5 | Top 5 |
| C | 6-10 | Top 10 |
| D | 11-15 | Mid-first |
| E | 16-20 | Late-first |
| F | 21-32 | End of first |
| G | 33-43 | Early second |
| H | 44-50 | Mid-second |
| I | 51+ R2 | Late second |
| J | R3 (≤100) | Early third |
| K | R3 (>100) | Late third |
| L | R4 | Fourth round |
| M | R5 | Fifth round |
| N | R6 | Sixth round |
| O | R7 | Seventh round |
| UDFA | Undrafted | Undrafted free agents |

Each tier has different expectations and thresholds. A first overall pick needs to be a franchise cornerstone; a seventh rounder just needs to make the roster and contribute.

### Configuration

All thresholds and expectations are defined in `config/bust-thresholds.json`. This includes:

- Expected AV by tier
- Expected games/starts
- Expected snap counts and percentages
- Bust/steal thresholds by tier
- Expected seasons with drafting team
- Award point values and normalization factors

## Running the Calculator

```bash
# Install dependencies
composer install

# Run tests
composer test

# Calculate steal scores (dry run)
php bin/calculate.php steals

# Calculate bust scores and persist to database
php bin/calculate.php --persist busts

# Calculate steal scores
php bin/calculate.php --persist steals

# Calculate for a specific team
php bin/calculate.php --team=49 --persist busts

# Calculate for a specific draft year
php bin/calculate.php --year=2018 --persist steals

# Calculate for a specific team's single draft class
php bin/calculate.php --team=49 --year=2018 --persist busts

# Enable debug logging (writes to logs/debug-log.json)
php bin/calculate.php --debug --persist steals
```

## Testing

Tests use fixture data in `tests/fixtures/known-players.json`. This file contains many different players all with different profiles that gives a good testing overview.

```bash
# Run all tests
composer test

# Run specific test file
./vendor/bin/phpunit tests/Unit/BustCalculatorTest.php
./vendor/bin/phpunit tests/Unit/StealCalculatorTest.php

# Run compilation check
./tests/compilation-test.sh
```

## Project Structure

```
├── bin/
│   └── calculate.php                # CLI entry point
├── config/
│   ├── bust-thresholds.json         # Expectations and thresholds
│   └── tier-mappings.json           # Draft pick → tier mapping
├── src/
│   ├── Calculator/
│   │   ├── AbstractCalculator.php
│   │   ├── CalculatorInterface.php
│   │   ├── CalculatorResult.php
│   │   └── Implementations/
│   │       ├── BustCalculator.php   # Bust algorithm
│   │       └── StealCalculator.php  # Steal algorithm
│   ├── Config/
│   │   └── ConfigLoader.php
│   ├── Data/
│   │   ├── CloudflarePlayerDataProvider.php
│   │   ├── PlayerDataProviderInterface.php
│   │   └── PlayerStats.php
│   ├── Runner/
│   │   └── CalculatorRunner.php
│   └── Tier/
│       └── TierResolver.php
└── tests/
    ├── compilation-test.sh
    ├── fixtures/
    │   └── known-players.json
    └── Unit/
        ├── BustCalculatorTest.php
        ├── StealCalculatorTest.php
        └── TierResolverTest.php
```

## License

Copyright (c) 2025 McGurk Labs LLC. All Rights Reserved.
This code is source-available for viewing and educational purposes only. See [LICENSE](LICENSE) for details.
