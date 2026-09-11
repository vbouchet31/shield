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

/**
 * Rebuild caches to ensure the domain method schema is read in.
 */
function shield_post_update_domains_method() {
  // Empty update to cause a cache rebuild so that the schema changes are read.
  // Sites upgrading from before the domain method existed have no value for
  // it, which reads as the exclude method and keeps the previous behavior.
}
