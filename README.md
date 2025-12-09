# Draft Signal Algorithm

This repository contains the algorithms used by Draft Signal to evaluate NFL draft picks. The primary purpose is transparency—showing how bust scores, steal scores, and other metrics are calculated.

## Bust Calculator

The bust calculator evaluates whether a draft pick failed to meet expectations based on their draft position. It considers:

- **Approximate Value (AV)** - Pro Football Reference's single-number metric for player value
- **Games played/started** - Availability and role
- **Snap counts** - Regular (offense + defense) and special teams snaps
- **Snap percentages** - Usage rate when active
- **Career length** - Seasons played with drafting team

### Tier System

Players are grouped into tiers (A-O) based on draft position:

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

Each tier has different expectations and bust thresholds. A first overall pick needs to be a franchise cornerstone; a seventh rounder just needs to make the roster and contribute.

### Configuration

All thresholds and expectations are defined in `config/bust-thresholds.json`. This includes:

- Expected AV by tier
- Expected games/starts
- Expected snap counts and percentages
- Bust threshold (what score triggers a bust classification)
- Expected seasons with drafting team

## Running the Calculator

```bash
# Install dependencies
composer install

# Run tests
composer test

# Calculate bust scores (persist - updates DB)
php bin/calculate.php --persist busts

# Calculate for a specific team
php bin/calculate.php --team=49 --persist busts

# Output as JSON
php bin/calculate.php --persist --json busts

# Actually update the database
php bin/calculate.php busts
```

### Environment Variables

The calculator requires these environment variables to connect to the database:

- `CLOUDFLARE_ACCOUNT_ID`
- `CLOUDFLARE_DATABASE_ID`
- `CLOUDFLARE_API_TOKEN`

## Testing

Tests use fixture data in `tests/fixtures/known-players.json`. This file contains players with known outcomes (busts vs. non-busts) that the algorithm must correctly classify.

```bash
# Run all tests
composer test

# Run specific test
./vendor/bin/phpunit --filter testKnownPlayersMatchExpectedBustStatus
```

## Project Structure

```
├── bin/
│   └── calculate.php    # CLI entry point
├── config/
│   ├── bust-thresholds.json       # Expectations and thresholds
│   └── tier-mappings.json         # Draft pick → tier mapping
├── src/
│   ├── Calculator/
│   │   ├── BustCalculator.php     # Main bust algorithm
│   │   ├── CalculatorInterface.php
│   │   └── CalculatorResult.php
│   ├── Config/
│   │   └── ConfigLoader.php
│   ├── Data/
│   │   ├── CloudflarePlayerDataProvider.php
│   │   ├── PlayerDataProviderInterface.php
│   │   └── PlayerStats.php
│   └── Tier/
│       └── TierResolver.php
└── tests/
    ├── fixtures/
    │   └── known-players.json
    └── Unit/
        ├── BustCalculatorTest.php
        └── TierResolverTest.php
```

## Future Calculators

- **Steal Calculator** - Identifies late-round picks who exceeded expectations
- **Draft Class Calculator** - Aggregates individual scores to grade a team's draft class
