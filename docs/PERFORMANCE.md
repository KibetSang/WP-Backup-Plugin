# Performance

## The 10 GB benchmark

`php -d memory_limit=128M tests/bench/bench.php` builds a synthetic site and backs it up, checks it and restores it, in
20-second steps (each one a separate `Jobs::run`, as separate requests are), then compares every file and the database.

| Part | Size |
|---|---|
| One video (`.mp4`) | 4 GB |
| Five archives (`.zip`) | 5 × 1 GB |
| Small files | 50,000 in 100 folders |
| Database | `wp_posts`, 1,024,288 rows (about 1 GB) |

Results (MacBook, PHP 8.5.12, MariaDB 12.2, local SSD, `memory_limit=128M`):

| Step | Time | Peak memory |
|---|---|---|
| Backup (database + 10 GB of files → 9.01 GB `.bbk`) | 59 s | 33.5 MB |
| Check with the key (every byte authenticated) | 17 s | 33.5 MB |
| Check before restoring | 19 s | 33.5 MB |
| Restore (files, database into temporary tables, swap) | 35 s | 33.5 MB |
| Every file (50,006) identical after restoring | yes | |
| `wp_posts` identical after restoring (`CHECKSUM TABLE`) | yes | |

A shared host is slower (disk, CPU and the 20-second steps with pauses between them): expect minutes to tens of minutes
for 10 GB. Memory does not grow with the size of the site.

Memory stays flat because everything is streamed in pieces of at most 2 MiB: files are read 1 MiB at a time and split
across steps mid-file, SELECTs are sized to about 8 MiB, INSERT statements to about 1 MiB, and uploads go up in 8–10 MiB
chunks.

## On shared hosting

- Each step stops at the "step length" setting (20 s by default), and never later than 60 % of PHP's
  `max_execution_time`, so a host that stops requests at 30 s gets 18-second steps.
- With the admin page open, steps follow each other at once. With it closed, WP-Cron carries on one step per tick; WP-Cron
  only runs when the site has visitors, unless the host calls `wp-cron.php` on a timer.
- For very large sites, `wp bastion backup` (WP-CLI) is fastest: long steps and no web server limits.
- Disk: a backup needs about as much free space as the files it holds (already-compressed media does not shrink). The
  backup stops with a clear message before it starts writing if there is not enough.
