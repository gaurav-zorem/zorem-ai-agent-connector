<?php
/**
 * PII redaction scope.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Defines which fields the redactor masks, which abilities are known to carry them, and which are exempt.
 *
 * Kept as data, in one place, so the boundary the profiles promise can be read
 * off a single list rather than inferred from behaviour.
 */
final class CoveredAbilities {

	/**
	 * Abilities whose responses are known to carry customer personal data.
	 *
	 * Their payload shape has been read from source, so address parts (`city`,
	 * `state`, …) are masked anywhere in them. Every other ability's result
	 * is masked too, for the agent that masks — with address parts masked only
	 * under a customer or address key. This list also drives the Customer data
	 * column on the Agent screen.
	 *
	 * WooCommerce's order and customer abilities return billing and shipping
	 * names, emails, phones and addresses (read from WooCommerce's
	 * src/Internal/Abilities/Domain/; the same list as AI Agent OS PRO's, so
	 * both mask alike). This plugin's get-users returns `display_name` and
	 * `user_email`, and get-comments returns the commenter's `display_name`
	 * and `email`.
	 *
	 * @var array<int, string>
	 */
	private const ABILITIES = array(
		'woocommerce/orders-query',
		'woocommerce/order-get',
		'woocommerce/order-notes-query',
		'woocommerce/order-add-note',
		'woocommerce/order-update-status',
		'woocommerce/customers-query',
		'woocommerce/customer-get',
		'ai-agent-os/get-users',
		'ai-agent-os/get-comments',
	);

	/**
	 * Field names masked wherever they appear in a covered response.
	 *
	 * Country is deliberately absent: a fulfillment agent legitimately needs
	 * the destination country to reason about shipping, and a country is not
	 * identifying on its own. Order totals, statuses, dates, line items and
	 * tracking fields are likewise untouched — masking them would break the
	 * job the agent was granted access to do.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = array(
		'first_name',
		'last_name',
		'email',
		'billing_email',
		'_billing_email',
		'phone',
		'billing_phone',
		'address_1',
		'address_2',
		'city',
		'postcode',
		'state',
		'company',
		'customer_note',
		'customer_ip_address',
		'customer_user_agent',
		// A shipment-tracking full-name field. No WooCommerce ability returns a scalar under this key.
		'customer',
		'customer_name',
		'display_name',
		'user_email',
		'user_login',
		'username',
		'nickname',
		'user_nicename',
		'formatted_billing_address',
		'formatted_shipping_address',
		'formatted_address',
		// An order note's text: the shop's own messages to and about the customer.
		'note',
		'author_email',
		'comment_author_email',
		'comment_author_ip',
		'ip_address',
		'user_agent',
	);

	/**
	 * Field names also masked as the end of a prefixed key: `shipping_phone`,
	 * `billing_first_name`, `_billing_address_1`.
	 *
	 * @var array<int, string>
	 */
	private const FIELD_SUFFIXES = array(
		'first_name',
		'last_name',
		'full_name',
		'email',
		'phone',
		'address_1',
		'address_2',
		'city',
		'postcode',
		'state',
		'company',
		'ip_address',
	);

	/**
	 * Fields that are generic words outside an address.
	 *
	 * Masked anywhere in a covered ability's result; elsewhere only under a key
	 * that names a customer or an address, so an unrelated `state` enum still
	 * passes its schema.
	 *
	 * @var array<int, string>
	 */
	private const ADDRESS_FIELDS = array( 'city', 'state', 'postcode', 'company', 'address_1', 'address_2', 'note' );

	/**
	 * Whether an ability's response is subject to redaction.
	 *
	 * @param string $ability_name Ability name.
	 */
	public function is_covered( string $ability_name ): bool {
		/**
		 * Filters which abilities have customer personal data masked.
		 *
		 * Only add abilities whose response shape you know: the redactor masks
		 * by field name at any depth, and the values it substitutes must remain
		 * valid against the ability's declared output schema.
		 *
		 * @param array<int, string> $abilities Ability names.
		 */
		$abilities = (array) apply_filters( 'ai_agent_os_pii_covered_abilities', self::ABILITIES );

		return in_array( $ability_name, $abilities, true );
	}

	/**
	 * The field names to mask, lowercased.
	 *
	 * @return array<int, string>
	 */
	public function fields(): array {
		/**
		 * Filters the field names masked as customer personal data.
		 *
		 * @param array<int, string> $fields Field names.
		 */
		$fields = (array) apply_filters( 'ai_agent_os_pii_field_list', self::FIELDS );

		return array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $fields ) ) ) );
	}

	/**
	 * Whether an ability's result is left unmasked when the agent masks.
	 *
	 * Masking runs on every ability's result, because an ability nobody has
	 * read is where customer data would slip through. None is exempt by default.
	 *
	 * @param string $ability_name Ability name.
	 */
	public function is_exempt( string $ability_name ): bool {
		/**
		 * Filters the abilities whose results are never masked.
		 *
		 * Only add an ability that cannot return a customer's details, and
		 * whose output schema masking would break.
		 *
		 * @param array<int, string> $abilities Ability names.
		 */
		$exempt = (array) apply_filters( 'ai_agent_os_pii_exempt_abilities', array() );

		return in_array( $ability_name, $exempt, true );
	}

	/**
	 * Field names masked as the end of a prefixed key.
	 *
	 * @return array<int, string>
	 */
	public function field_suffixes(): array {
		return self::FIELD_SUFFIXES;
	}

	/**
	 * Fields masked outside covered abilities only under a customer or address key.
	 *
	 * @return array<int, string>
	 */
	public function address_fields(): array {
		return self::ADDRESS_FIELDS;
	}

	/**
	 * The covered abilities, for the admin UI.
	 *
	 * @return array<int, string>
	 */
	public function abilities(): array {
		return (array) apply_filters( 'ai_agent_os_pii_covered_abilities', self::ABILITIES );
	}
}
