<?php

/**
 * @file
 * Post update functions for Shield.
 */

/**
 * Rebuild caches to ensure schema changes are read in.
 */
function shield_post_update_domain_allowlist() {
  // Empty update to cause a cache rebuild so that the schema changes are read.
}

/**
 * Rebuild caches to ensure the user agent allowlist schema is read in.
 */
function shield_post_update_user_agent_allowlist() {
  // Empty update to cause a cache rebuild so that the schema changes are read.
}
