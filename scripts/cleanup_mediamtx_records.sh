#!/usr/bin/env bash
set -euo pipefail

VIDEO_ROOT="${VIDEO_ROOT:-/home/zjl/Desktop/videos}"
MAX_AGE_MINUTES="${MAX_AGE_MINUTES:-1440}"
MAX_DISK_PERCENT="${MAX_DISK_PERCENT:-85}"
LOG_FILE="${LOG_FILE:-/tmp/cleanup_mediamtx_records.log}"
TARGET_DIR_GLOB="cam*"

log() {
  local msg="[$(date '+%F %T')] $*"
  echo "$msg" | tee -a "$LOG_FILE" >&2
}

is_media_file() {
  local path="$1"
  case "${path,,}" in
    *.mp4|*.m4v|*.mov|*.webm|*.m3u8|*.ts) return 0 ;;
    *) return 1 ;;
  esac
}

if [ ! -d "$VIDEO_ROOT" ]; then
  log "skip: directory not found: $VIDEO_ROOT"
  exit 0
fi

readarray -t TARGET_DIRS < <(find "$VIDEO_ROOT" -mindepth 1 -maxdepth 1 -type d -name "$TARGET_DIR_GLOB" | sort)
if [ "${#TARGET_DIRS[@]}" -eq 0 ]; then
  log "skip: no target camera directories matched pattern $TARGET_DIR_GLOB under $VIDEO_ROOT"
  exit 0
fi

log "start cleanup in $VIDEO_ROOT for directories: $(printf '%s ' "${TARGET_DIRS[@]##*/}")"

collect_media_files() {
  local dir file
  while read -r dir; do
    [ -n "$dir" ] || continue
    while read -r file; do
      [ -n "$file" ] || continue
      if is_media_file "$file"; then
        printf '%s\n' "$file"
      fi
    done < <(find "$dir" -type f)
  done < <(printf '%s\n' "${TARGET_DIRS[@]}")
}

cleanup_empty_dirs() {
  local dir
  while read -r dir; do
    [ -n "$dir" ] || continue
    find "$dir" -type d -empty -delete
  done < <(printf '%s\n' "${TARGET_DIRS[@]}")
}

get_disk_percent() {
  df -P "$VIDEO_ROOT" | awk 'NR==2 {gsub(/%/, "", $5); print $5}'
}

while read -r file; do
  [ -n "$file" ] || continue
  if find "$file" -mmin +"$MAX_AGE_MINUTES" | grep -q .; then
    log "delete expired file: $file"
    rm -f -- "$file"
  fi
done < <(collect_media_files)

cleanup_empty_dirs

disk_percent="$(get_disk_percent)"
while [ "${disk_percent:-0}" -ge "$MAX_DISK_PERCENT" ]; do
  oldest_file="$(
    while read -r dir; do
      [ -n "$dir" ] || continue
      while read -r file; do
        [ -n "$file" ] || continue
        if is_media_file "$file"; then
          stat --format='%Y %n' "$file"
        fi
      done < <(find "$dir" -type f)
    done < <(printf '%s\n' "${TARGET_DIRS[@]}") | sort -n | head -n 1 | cut -d' ' -f2-
  )"

  if [ -z "$oldest_file" ]; then
    log "stop: no more target camera files to delete"
    break
  fi

  log "disk ${disk_percent}% >= ${MAX_DISK_PERCENT}%, delete oldest target camera file: $oldest_file"
  rm -f -- "$oldest_file"
  disk_percent="$(get_disk_percent)"
done

cleanup_empty_dirs
log "cleanup done, disk usage=${disk_percent}%"
