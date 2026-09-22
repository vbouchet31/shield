<?php

namespace Drupal\shield\Form;

/**
 * Form for adding a new Shield policy.
 *
 * Conditions and credential providers can only be configured once the
 * policy exists (they're edited via their own AJAX-modal routes, which need
 * an entity ID) - see ShieldPolicyFormEdit.
 */
class ShieldPolicyFormAdd extends ShieldPolicyForm {}
