<?php

defined( 'ABSPATH' ) || exit;

trait ERankly_Business {

	/**
	 * Registers the canonical local-business profile.
	 *
	 * @return void
	 */
	public static function register_business_settings(): void {
		register_setting(
			'erankly_business_settings',
			self::BUSINESS_SETTINGS_OPTION,
			array(
				'default'           => self::get_default_business_settings(),
				'sanitize_callback' => array( self::class, 'sanitize_business_settings' ),
				'type'              => 'array',
			)
		);
	}

	/**
	 * Adds the local-business settings screen below Settings.
	 *
	 * @return void
	 */
	public static function register_business_settings_page(): void {
		add_options_page(
			__( 'Local business', 'easyrankly' ),
			__( 'Local business', 'easyrankly' ),
			'manage_options',
			'erankly-local-business',
			array( self::class, 'render_business_settings_page' )
		);
	}

	private static function get_local_business_types(): array {
		static $types = array();
		$key = self::get_cache_context_key();

		if ( isset( $types[ $key ] ) ) {
			return $types[ $key ];
		}

		$types[ $key ] = array();

		foreach ( self::get_local_business_type_groups() as $group_types ) {
			$types[ $key ] += $group_types;
		}

		return $types[ $key ];
	}

	private static function get_local_business_type_groups(): array {
		return array(
			__( 'General and public services', 'easyrankly' ) => array(
				'LocalBusiness'            => __( 'Local business (generic)', 'easyrankly' ),
				'ProfessionalService'      => __( 'Professional service', 'easyrankly' ),
				'RealEstateAgent'          => __( 'Real estate agent', 'easyrankly' ),
				'TravelAgency'             => __( 'Travel agency', 'easyrankly' ),
				'EmploymentAgency'         => __( 'Employment agency', 'easyrankly' ),
				'ChildCare'                => __( 'Child care', 'easyrankly' ),
				'Library'                  => __( 'Library', 'easyrankly' ),
				'SelfStorage'              => __( 'Self storage', 'easyrankly' ),
				'TouristInformationCenter' => __( 'Tourist information center', 'easyrankly' ),
				'DryCleaningOrLaundry'     => __( 'Dry cleaning or laundry', 'easyrankly' ),
				'InternetCafe'             => __( 'Internet cafe', 'easyrankly' ),
				'RecyclingCenter'          => __( 'Recycling center', 'easyrankly' ),
				'AnimalShelter'            => __( 'Animal shelter', 'easyrankly' ),
				'GovernmentOffice'         => __( 'Government office', 'easyrankly' ),
				'PostOffice'               => __( 'Post office', 'easyrankly' ),
				'EmergencyService'         => __( 'Emergency service', 'easyrankly' ),
			),
			__( 'Legal and financial services', 'easyrankly' ) => array(
				'LegalService'      => __( 'Legal service', 'easyrankly' ),
				'Attorney'          => __( 'Attorney', 'easyrankly' ),
				'Notary'            => __( 'Notary', 'easyrankly' ),
				'FinancialService'  => __( 'Financial service', 'easyrankly' ),
				'AccountingService' => __( 'Accounting service', 'easyrankly' ),
				'BankOrCreditUnion' => __( 'Bank or credit union', 'easyrankly' ),
				'AutomatedTeller'   => __( 'Automated teller machine (ATM)', 'easyrankly' ),
				'InsuranceAgency'   => __( 'Insurance agency', 'easyrankly' ),
			),
			__( 'Health and beauty', 'easyrankly' ) => array(
				'MedicalBusiness'         => __( 'Medical business', 'easyrankly' ),
				'MedicalClinic'           => __( 'Medical clinic', 'easyrankly' ),
				'Dentist'                 => __( 'Dentist', 'easyrankly' ),
				'Physician'               => __( 'Physician', 'easyrankly' ),
				'Pharmacy'                => __( 'Pharmacy', 'easyrankly' ),
				'Optician'                => __( 'Optician', 'easyrankly' ),
				'Physiotherapy'           => __( 'Physiotherapy', 'easyrankly' ),
				'HealthAndBeautyBusiness' => __( 'Health and beauty business', 'easyrankly' ),
				'BeautySalon'             => __( 'Beauty salon', 'easyrankly' ),
				'DaySpa'                  => __( 'Day spa', 'easyrankly' ),
				'HairSalon'               => __( 'Hair salon', 'easyrankly' ),
				'NailSalon'               => __( 'Nail salon', 'easyrankly' ),
				'TattooParlor'            => __( 'Tattoo parlor', 'easyrankly' ),
				'HealthClub'              => __( 'Health club', 'easyrankly' ),
			),
			__( 'Sports and recreation', 'easyrankly' ) => array(
				'ExerciseGym'  => __( 'Gym', 'easyrankly' ),
				'GolfCourse'   => __( 'Golf course', 'easyrankly' ),
				'BowlingAlley' => __( 'Bowling alley', 'easyrankly' ),
				'SportsClub'   => __( 'Sports club', 'easyrankly' ),
				'TennisComplex' => __( 'Tennis complex', 'easyrankly' ),
				'SkiResort'    => __( 'Ski resort', 'easyrankly' ),
			),
			__( 'Food and drink', 'easyrankly' ) => array(
				'FoodEstablishment'  => __( 'Food establishment', 'easyrankly' ),
				'Bakery'             => __( 'Bakery', 'easyrankly' ),
				'BarOrPub'           => __( 'Bar or pub', 'easyrankly' ),
				'Brewery'            => __( 'Brewery', 'easyrankly' ),
				'CafeOrCoffeeShop'   => __( 'Cafe or coffee shop', 'easyrankly' ),
				'Distillery'         => __( 'Distillery', 'easyrankly' ),
				'FastFoodRestaurant' => __( 'Fast food restaurant', 'easyrankly' ),
				'IceCreamShop'       => __( 'Ice cream shop', 'easyrankly' ),
				'Restaurant'         => __( 'Restaurant', 'easyrankly' ),
				'Winery'             => __( 'Winery', 'easyrankly' ),
			),
			__( 'Automotive', 'easyrankly' ) => array(
				'AutomotiveBusiness' => __( 'Automotive business', 'easyrankly' ),
				'AutoBodyShop'       => __( 'Auto body shop', 'easyrankly' ),
				'AutoDealer'         => __( 'Auto dealer', 'easyrankly' ),
				'AutoPartsStore'     => __( 'Auto parts store', 'easyrankly' ),
				'AutoRental'         => __( 'Auto rental', 'easyrankly' ),
				'AutoRepair'         => __( 'Auto repair', 'easyrankly' ),
				'AutoWash'           => __( 'Auto wash', 'easyrankly' ),
				'GasStation'         => __( 'Gas station', 'easyrankly' ),
				'MotorcycleDealer'   => __( 'Motorcycle dealer', 'easyrankly' ),
				'MotorcycleRepair'   => __( 'Motorcycle repair', 'easyrankly' ),
			),
			__( 'Lodging', 'easyrankly' ) => array(
				'LodgingBusiness' => __( 'Lodging business', 'easyrankly' ),
				'BedAndBreakfast' => __( 'Bed and breakfast', 'easyrankly' ),
				'Campground'      => __( 'Campground', 'easyrankly' ),
				'Hostel'          => __( 'Hostel', 'easyrankly' ),
				'Hotel'           => __( 'Hotel', 'easyrankly' ),
				'Motel'           => __( 'Motel', 'easyrankly' ),
				'Resort'          => __( 'Resort', 'easyrankly' ),
				'VacationRental'  => __( 'Vacation rental', 'easyrankly' ),
			),
			__( 'Stores and shopping', 'easyrankly' ) => array(
				'Store'               => __( 'Store', 'easyrankly' ),
				'BikeStore'           => __( 'Bike store', 'easyrankly' ),
				'BookStore'           => __( 'Book store', 'easyrankly' ),
				'ClothingStore'       => __( 'Clothing store', 'easyrankly' ),
				'ComputerStore'       => __( 'Computer store', 'easyrankly' ),
				'ConvenienceStore'    => __( 'Convenience store', 'easyrankly' ),
				'DepartmentStore'     => __( 'Department store', 'easyrankly' ),
				'ElectronicsStore'    => __( 'Electronics store', 'easyrankly' ),
				'Florist'             => __( 'Florist', 'easyrankly' ),
				'FurnitureStore'      => __( 'Furniture store', 'easyrankly' ),
				'GardenStore'         => __( 'Garden store', 'easyrankly' ),
				'GroceryStore'        => __( 'Grocery store', 'easyrankly' ),
				'HardwareStore'       => __( 'Hardware store', 'easyrankly' ),
				'HobbyShop'           => __( 'Hobby shop', 'easyrankly' ),
				'HomeGoodsStore'      => __( 'Home goods store', 'easyrankly' ),
				'JewelryStore'        => __( 'Jewelry store', 'easyrankly' ),
				'LiquorStore'         => __( 'Liquor store', 'easyrankly' ),
				'MensClothingStore'   => __( "Men's clothing store", 'easyrankly' ),
				'MobilePhoneStore'    => __( 'Mobile phone store', 'easyrankly' ),
				'MovieRentalStore'    => __( 'Movie rental store', 'easyrankly' ),
				'MusicStore'          => __( 'Music store', 'easyrankly' ),
				'OfficeEquipmentStore' => __( 'Office equipment store', 'easyrankly' ),
				'OutletStore'         => __( 'Outlet store', 'easyrankly' ),
				'PawnShop'            => __( 'Pawn shop', 'easyrankly' ),
				'PetStore'            => __( 'Pet store', 'easyrankly' ),
				'ShoeStore'           => __( 'Shoe store', 'easyrankly' ),
				'SportingGoodsStore'  => __( 'Sporting goods store', 'easyrankly' ),
				'TireShop'            => __( 'Tire shop', 'easyrankly' ),
				'ToyStore'            => __( 'Toy store', 'easyrankly' ),
				'WholesaleStore'      => __( 'Wholesale store', 'easyrankly' ),
				'ShoppingCenter'      => __( 'Shopping center', 'easyrankly' ),
			),
			__( 'Arts and entertainment', 'easyrankly' ) => array(
				'EntertainmentBusiness' => __( 'Entertainment business', 'easyrankly' ),
				'AdultEntertainment'    => __( 'Adult entertainment', 'easyrankly' ),
				'AmusementPark'         => __( 'Amusement park', 'easyrankly' ),
				'ArtGallery'            => __( 'Art gallery', 'easyrankly' ),
				'Casino'                => __( 'Casino', 'easyrankly' ),
				'ComedyClub'            => __( 'Comedy club', 'easyrankly' ),
				'MovieTheater'          => __( 'Movie theater', 'easyrankly' ),
				'Museum'                => __( 'Museum', 'easyrankly' ),
				'MusicVenue'            => __( 'Music venue', 'easyrankly' ),
				'NightClub'             => __( 'Night club', 'easyrankly' ),
				'RadioStation'          => __( 'Radio station', 'easyrankly' ),
				'TelevisionStation'     => __( 'Television station', 'easyrankly' ),
			),
			__( 'Home and construction', 'easyrankly' ) => array(
				'HomeAndConstructionBusiness' => __( 'Home and construction business', 'easyrankly' ),
				'Electrician'                 => __( 'Electrician', 'easyrankly' ),
				'GeneralContractor'           => __( 'General contractor', 'easyrankly' ),
				'HVACBusiness'                => __( 'HVAC business', 'easyrankly' ),
				'HousePainter'                => __( 'House painter', 'easyrankly' ),
				'Locksmith'                   => __( 'Locksmith', 'easyrankly' ),
				'MovingCompany'               => __( 'Moving company', 'easyrankly' ),
				'Plumber'                     => __( 'Plumber', 'easyrankly' ),
				'RoofingContractor'           => __( 'Roofing contractor', 'easyrankly' ),
			),
		);
	}

	private static function get_weekdays(): array {
		return array(
			'Monday'    => __( 'Monday' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			'Tuesday'   => __( 'Tuesday' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			'Wednesday' => __( 'Wednesday' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			'Thursday'  => __( 'Thursday' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			'Friday'    => __( 'Friday' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			'Saturday'  => __( 'Saturday' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			'Sunday'    => __( 'Sunday' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		);
	}

	private static function get_default_opening_hours(): array {
		$hours = array();

		foreach ( array_keys( self::get_weekdays() ) as $day ) {
			$hours[ $day ] = array(
				'enabled' => false,
				'opens'   => '',
				'closes'  => '',
			);
		}

		return $hours;
	}

	private static function get_default_business_settings(): array {
		return array(
			'enabled'          => false,
			'business_type'    => 'LocalBusiness',
			'name'             => '',
			'street_address'   => '',
			'address_locality' => '',
			'address_region'   => '',
			'postal_code'      => '',
			'address_country'  => '',
			'telephone'        => '',
			'page_id'          => 0,
			'opening_hours'    => self::get_default_opening_hours(),
			'latitude'         => '',
			'longitude'        => '',
			'gbp_url'          => '',
		);
	}

	private static function sanitize_coordinate( $value, $min, $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = str_replace( ',', '.', trim( (string) $value ) );

		if ( ! preg_match( '/^[+-]?[0-9]{1,3}(?:\.[0-9]+)?$/', $value ) ) {
			return '';
		}

		$number = (float) $value;

		return $number >= $min && $number <= $max ? $value : '';
	}

	private static function add_business_settings_notice( $code, $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::BUSINESS_SETTINGS_OPTION, $code, $message );
		}
	}

	private static function get_country_codes(): array {
		return array(
			'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
			'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ',
			'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ',
			'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ',
			'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET',
			'FI', 'FJ', 'FK', 'FM', 'FO', 'FR',
			'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY',
			'HK', 'HM', 'HN', 'HR', 'HT', 'HU',
			'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT',
			'JE', 'JM', 'JO', 'JP',
			'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ',
			'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY',
			'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ',
			'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ',
			'OM',
			'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY',
			'QA',
			'RE', 'RO', 'RS', 'RU', 'RW',
			'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ',
			'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ',
			'UA', 'UG', 'UM', 'US', 'UY', 'UZ',
			'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU',
			'WF', 'WS',
			'YE', 'YT',
			'ZA', 'ZM', 'ZW',
		);
	}

	private static function sanitize_business_text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Sanitizes the canonical local-business profile.
	 *
	 * @param mixed $settings Submitted settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize_business_settings( $settings ): array {
		return self::normalize_business_settings( $settings, true );
	}

	private static function normalize_business_settings( $settings, $report_errors ): array {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$types         = self::get_local_business_types();
		$business_type = isset( $settings['business_type'] ) && is_string( $settings['business_type'] )
			? sanitize_key( $settings['business_type'] )
			: 'localbusiness';
		$matched_type  = 'LocalBusiness';

		foreach ( array_keys( $types ) as $type ) {
			if ( strtolower( $type ) === strtolower( $business_type ) ) {
				$matched_type = $type;
				break;
			}
		}

		$opening_hours = self::get_default_opening_hours();
		$submitted     = isset( $settings['opening_hours'] ) && is_array( $settings['opening_hours'] )
			? $settings['opening_hours']
			: array();

		foreach ( $opening_hours as $day => $empty_hours ) {
			$day_hours = isset( $submitted[ $day ] ) && is_array( $submitted[ $day ] ) ? $submitted[ $day ] : array();
			$opens     = isset( $day_hours['opens'] ) && is_string( $day_hours['opens'] ) ? trim( $day_hours['opens'] ) : '';
			$closes    = isset( $day_hours['closes'] ) && is_string( $day_hours['closes'] ) ? trim( $day_hours['closes'] ) : '';
			$valid     = preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $opens )
				&& preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $closes );

			if ( ! empty( $day_hours['enabled'] ) && $valid ) {
				$opening_hours[ $day ] = array(
					'enabled' => true,
					'opens'   => $opens,
					'closes'  => $closes,
				);
			} elseif ( $report_errors && ! empty( $day_hours['enabled'] ) ) {
				self::add_business_settings_notice(
					'erankly-business-hours-' . strtolower( $day ),
					sprintf(
						/* translators: %s: weekday name. */
						__( '%s was not enabled because its opening hours are incomplete or invalid.', 'easyrankly' ),
						self::get_weekdays()[ $day ]
					)
				);
			}
		}

		$telephone = isset( $settings['telephone'] ) && is_scalar( $settings['telephone'] )
			? preg_replace( '/[^0-9+().\s\-\/]/u', '', sanitize_text_field( (string) $settings['telephone'] ) )
			: '';
		$country = isset( $settings['address_country'] ) && is_scalar( $settings['address_country'] )
			? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $settings['address_country'] ) )
			: '';
		$country = substr( $country, 0, 2 );

		if ( '' !== $country && ! in_array( $country, self::get_country_codes(), true ) ) {
			if ( $report_errors ) {
				self::add_business_settings_notice(
					'erankly-business-country',
					__( 'The country code was not saved because it is not a valid ISO 3166-1 alpha-2 code, for example IT.', 'easyrankly' )
				);
			}

			$country = '';
		}

		$latitude_input  = isset( $settings['latitude'] ) && is_scalar( $settings['latitude'] ) ? trim( (string) $settings['latitude'] ) : '';
		$longitude_input = isset( $settings['longitude'] ) && is_scalar( $settings['longitude'] ) ? trim( (string) $settings['longitude'] ) : '';
		$latitude        = self::sanitize_coordinate( $latitude_input, -90, 90 );
		$longitude       = self::sanitize_coordinate( $longitude_input, -180, 180 );

		if ( '' === $latitude || '' === $longitude ) {
			$discarded = '' !== $latitude_input || '' !== $longitude_input;
			$latitude  = '';
			$longitude = '';

			if ( $report_errors && $discarded ) {
				self::add_business_settings_notice(
					'erankly-business-coordinates',
					__( 'The coordinates were not saved. Enter both latitude and longitude as decimal numbers, for example 45.46420 and 9.19000.', 'easyrankly' )
				);
			}
		}

		return array(
			'enabled'          => ! empty( $settings['enabled'] ),
			'business_type'    => $matched_type,
			'name'             => self::sanitize_business_text( $settings['name'] ?? null ),
			'street_address'   => self::sanitize_business_text( $settings['street_address'] ?? null ),
			'address_locality' => self::sanitize_business_text( $settings['address_locality'] ?? null ),
			'address_region'   => self::sanitize_business_text( $settings['address_region'] ?? null ),
			'postal_code'      => self::sanitize_business_text( $settings['postal_code'] ?? null ),
			'address_country'  => $country,
			'telephone'        => is_string( $telephone ) ? trim( $telephone ) : '',
			'page_id'          => isset( $settings['page_id'] ) ? absint( $settings['page_id'] ) : 0,
			'opening_hours'    => $opening_hours,
			'latitude'         => $latitude,
			'longitude'        => $longitude,
			'gbp_url'          => self::sanitize_social_url( $settings['gbp_url'] ?? null ),
		);
	}

	/**
	 * Returns the canonical local-business profile.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_business_profile(): array {
		$key = self::get_cache_context_key();

		if ( ! isset( self::$business_profile_cache[ $key ] ) ) {
			self::$business_profile_cache[ $key ] = self::normalize_business_settings( get_option( self::BUSINESS_SETTINGS_OPTION, array() ), false );
		}

		return self::$business_profile_cache[ $key ];
	}

	private static function get_business_readiness_issues( $settings ): array {
		$required = array(
			'name'             => __( 'business name', 'easyrankly' ),
			'street_address'   => __( 'street address', 'easyrankly' ),
			'address_locality' => __( 'city', 'easyrankly' ),
			'postal_code'      => __( 'postal code', 'easyrankly' ),
			'address_country'  => __( 'country code', 'easyrankly' ),
			'telephone'        => __( 'telephone', 'easyrankly' ),
		);
		$missing  = array();

		foreach ( $required as $key => $label ) {
			if ( empty( $settings[ $key ] ) ) {
				$missing[] = $label;
			}
		}

		return $missing;
	}

	private static function is_business_profile_ready( $settings ): bool {
		return ! empty( $settings['enabled'] ) && empty( self::get_business_readiness_issues( $settings ) );
	}

	/**
	 * Renders the local-business settings page.
	 *
	 * @return void
	 */
	public static function render_business_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get_business_profile();
		$option   = self::BUSINESS_SETTINGS_OPTION;
		$missing  = self::get_business_readiness_issues( $settings );
		$summary  = array();

		if ( self::is_business_profile_ready( $settings ) ) {
			$summary[] = $settings['name'];
			$summary[] = $settings['street_address'];
			$summary[] = trim( $settings['postal_code'] . ' ' . $settings['address_locality'] . ' ' . $settings['address_region'] );
			$summary[] = $settings['address_country'];
			$summary[] = $settings['telephone'];

			foreach ( self::get_weekdays() as $day => $label ) {
				$hours = $settings['opening_hours'][ $day ];

				if ( ! empty( $hours['enabled'] ) ) {
					$summary[] = $label . ': ' . $hours['opens'] . '-' . $hours['closes'];
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Local business', 'easyrankly' ); ?></h1>
			<p><?php esc_html_e( 'This profile is the single source used by the visible business block and automatic structured data.', 'easyrankly' ); ?></p>
			<?php settings_errors( self::BUSINESS_SETTINGS_OPTION ); ?>
			<?php if ( $settings['enabled'] && ! empty( $missing ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: comma-separated field names. */
						esc_html__( 'LocalBusiness output is paused until these fields are completed: %s.', 'easyrankly' ),
						esc_html( implode( ', ', $missing ) )
					);
					?>
				</p></div>
			<?php endif; ?>
			<form action="options.php" method="post">
				<?php settings_fields( 'erankly_business_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Local business', 'easyrankly' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>> <?php esc_html_e( 'This site represents one physical business location', 'easyrankly' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="erankly_business_type"><?php esc_html_e( 'Business type', 'easyrankly' ); ?></label></th>
						<td><select id="erankly_business_type" name="<?php echo esc_attr( $option ); ?>[business_type]">
							<?php
							$types         = self::get_local_business_types();
							$grouped_types = array();

							foreach ( self::get_local_business_type_groups() as $group_label => $group_types ) :
								$group_types = array_intersect_key( $group_types, $types );

								if ( empty( $group_types ) ) {
									continue;
								}

								$grouped_types += $group_types;
								?>
								<optgroup label="<?php echo esc_attr( $group_label ); ?>">
									<?php foreach ( array_keys( $group_types ) as $type ) : ?>
										<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $settings['business_type'], $type ); ?>><?php echo esc_html( $types[ $type ] ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
							<?php foreach ( array_diff_key( $types, $grouped_types ) as $type => $label ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $settings['business_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select><p class="description"><?php esc_html_e( 'Choose the most specific applicable Schema.org type.', 'easyrankly' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="erankly_business_name"><?php esc_html_e( 'Business name', 'easyrankly' ); ?></label></th>
						<td><input type="text" class="regular-text" id="erankly_business_name" name="<?php echo esc_attr( $option ); ?>[name]" value="<?php echo esc_attr( $settings['name'] ); ?>" autocomplete="organization"><p class="description"><?php esc_html_e( 'Use the exact name shown in Google Business Profile.', 'easyrankly' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="erankly_street_address"><?php esc_html_e( 'Street address', 'easyrankly' ); ?></label></th>
						<td><input type="text" class="regular-text" id="erankly_street_address" name="<?php echo esc_attr( $option ); ?>[street_address]" value="<?php echo esc_attr( $settings['street_address'] ); ?>" autocomplete="street-address"></td>
					</tr>
					<tr>
						<th scope="row"><label for="erankly_address_locality"><?php esc_html_e( 'City', 'easyrankly' ); ?></label></th>
						<td><input type="text" class="regular-text" id="erankly_address_locality" name="<?php echo esc_attr( $option ); ?>[address_locality]" value="<?php echo esc_attr( $settings['address_locality'] ); ?>" autocomplete="address-level2"></td>
					</tr>
					<tr>
						<th scope="row"><label for="erankly_address_region"><?php esc_html_e( 'Region', 'easyrankly' ); ?></label></th>
						<td><input type="text" class="regular-text" id="erankly_address_region" name="<?php echo esc_attr( $option ); ?>[address_region]" value="<?php echo esc_attr( $settings['address_region'] ); ?>" autocomplete="address-level1"></td>
					</tr>
					<tr>
						<th scope="row"><label for="erankly_postal_code"><?php esc_html_e( 'Postal code', 'easyrankly' ); ?></label></th>
						<td><input type="text" class="regular-text" id="erankly_postal_code" name="<?php echo esc_attr( $option ); ?>[postal_code]" value="<?php echo esc_attr( $settings['postal_code'] ); ?>" autocomplete="postal-code"></td>
					</tr>
					<tr>
						<th scope="row"><label for="erankly_address_country"><?php esc_html_e( 'Country', 'easyrankly' ); ?></label></th>
						<td><input type="text" class="small-text" id="erankly_address_country" name="<?php echo esc_attr( $option ); ?>[address_country]" maxlength="2" value="<?php echo esc_attr( $settings['address_country'] ); ?>" autocomplete="country"><p class="description"><?php esc_html_e( 'Two-letter country code, for example IT.', 'easyrankly' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="erankly_telephone"><?php esc_html_e( 'Telephone', 'easyrankly' ); ?></label></th>
						<td><input type="tel" class="regular-text" id="erankly_telephone" name="<?php echo esc_attr( $option ); ?>[telephone]" value="<?php echo esc_attr( $settings['telephone'] ); ?>" autocomplete="tel"><p class="description"><?php esc_html_e( 'Include country and area codes.', 'easyrankly' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="erankly_business_page_id"><?php esc_html_e( 'Business page', 'easyrankly' ); ?></label></th>
						<td><?php
						// wp_dropdown_pages() escapes each of these values when it renders them.
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
						wp_dropdown_pages(
							array(
								'name'              => $option . '[page_id]',
								'id'                => 'erankly_business_page_id',
								'selected'          => $settings['page_id'],
								'show_option_none'  => __( 'Homepage', 'easyrankly' ),
								'option_none_value' => 0,
							)
						);
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						?><p class="description"><?php esc_html_e( 'Canonical page describing this location.', 'easyrankly' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Opening hours', 'easyrankly' ); ?></th>
						<td><table><thead><tr><th><?php esc_html_e( 'Open', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Day', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Opens', 'easyrankly' ); ?></th><th><?php esc_html_e( 'Closes', 'easyrankly' ); ?></th></tr></thead><tbody>
						<?php foreach ( self::get_weekdays() as $day => $label ) : ?>
							<?php
							$hours = $settings['opening_hours'][ $day ];
							$open_label = sprintf(
								/* translators: %s: weekday name. */
								__( '%s is open', 'easyrankly' ),
								$label
							);
							$opening_time_label = sprintf(
								/* translators: %s: weekday name. */
								__( '%s opening time', 'easyrankly' ),
								$label
							);
							$closing_time_label = sprintf(
								/* translators: %s: weekday name. */
								__( '%s closing time', 'easyrankly' ),
								$label
							);
							?>
							<tr>
								<td><input aria-label="<?php echo esc_attr( $open_label ); ?>" type="checkbox" name="<?php echo esc_attr( $option ); ?>[opening_hours][<?php echo esc_attr( $day ); ?>][enabled]" value="1" <?php checked( $hours['enabled'] ); ?>></td>
								<td><?php echo esc_html( $label ); ?></td>
								<td><input aria-label="<?php echo esc_attr( $opening_time_label ); ?>" type="time" name="<?php echo esc_attr( $option ); ?>[opening_hours][<?php echo esc_attr( $day ); ?>][opens]" value="<?php echo esc_attr( $hours['opens'] ); ?>"></td>
								<td><input aria-label="<?php echo esc_attr( $closing_time_label ); ?>" type="time" name="<?php echo esc_attr( $option ); ?>[opening_hours][<?php echo esc_attr( $day ); ?>][closes]" value="<?php echo esc_attr( $hours['closes'] ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody></table><p class="description"><?php esc_html_e( 'Closing times earlier than opening times represent overnight hours.', 'easyrankly' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Coordinates', 'easyrankly' ); ?></th>
						<td>
							<label for="erankly_latitude"><?php esc_html_e( 'Latitude', 'easyrankly' ); ?></label> <input id="erankly_latitude" name="<?php echo esc_attr( $option ); ?>[latitude]" type="number" min="-90" max="90" step="any" value="<?php echo esc_attr( $settings['latitude'] ); ?>">
							<label for="erankly_longitude"><?php esc_html_e( 'Longitude', 'easyrankly' ); ?></label> <input id="erankly_longitude" name="<?php echo esc_attr( $option ); ?>[longitude]" type="number" min="-180" max="180" step="any" value="<?php echo esc_attr( $settings['longitude'] ); ?>">
							<p class="description"><?php esc_html_e( 'Saving manually entered coordinates confirms them. Both values are required; use at least five decimal places for building-level precision.', 'easyrankly' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="erankly_gbp_url"><?php esc_html_e( 'Google Business Profile', 'easyrankly' ); ?></label></th>
						<td><input class="regular-text code" id="erankly_gbp_url" name="<?php echo esc_attr( $option ); ?>[gbp_url]" type="url" value="<?php echo esc_attr( $settings['gbp_url'] ); ?>"><p class="description"><?php esc_html_e( 'Public Google Maps or Business Profile URL. It is emitted as sameAs; EasyRankly does not edit the remote profile.', 'easyrankly' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php if ( ! empty( $summary ) ) : ?>
				<h2><?php esc_html_e( 'Google Business Profile reference', 'easyrankly' ); ?></h2>
				<p><?php esc_html_e( 'Use these exact values when reviewing the remote Google profile.', 'easyrankly' ); ?></p>
				<textarea class="large-text code" rows="12" readonly><?php echo esc_textarea( implode( "\n", $summary ) ); ?></textarea>
			<?php endif; ?>
			<p><?php esc_html_e( 'Display these canonical details with the EasyRankly Business Profile block or the [easyrankly_business_profile] shortcode.', 'easyrankly' ); ?></p>
		</div>
		<?php
	}

	private static function get_business_page_url( $settings ): string {
		$page_id = ! empty( $settings['page_id'] ) ? absint( $settings['page_id'] ) : 0;
		$url     = $page_id && 'publish' === get_post_status( $page_id ) ? get_permalink( $page_id ) : home_url( '/' );
		$url     = self::sanitize_social_url( $url );

		return '' !== $url ? $url : home_url( '/' );
	}

	private static function is_business_identity_context(): bool {
		$settings = self::get_business_profile();

		if (
			! self::is_business_profile_ready( $settings )
			|| ! self::is_public_request()
		) {
			return false;
		}

		$page_id = absint( $settings['page_id'] );

		if ( ! $page_id ) {
			return is_front_page();
		}

		if ( ! is_page( $page_id ) || 'publish' !== get_post_status( $page_id ) || post_password_required( $page_id ) ) {
			return false;
		}

		return ! self::is_noindex( $page_id );
	}

	private static function get_opening_hours_schema( $settings ): array {
		$groups = array();
		$hours  = isset( $settings['opening_hours'] ) && is_array( $settings['opening_hours'] )
			? $settings['opening_hours']
			: array();

		foreach ( array_keys( self::get_weekdays() ) as $day ) {
			if ( empty( $hours[ $day ]['enabled'] ) || empty( $hours[ $day ]['opens'] ) || empty( $hours[ $day ]['closes'] ) ) {
				continue;
			}

			$key = $hours[ $day ]['opens'] . '|' . $hours[ $day ]['closes'];

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'days'   => array(),
					'opens'  => $hours[ $day ]['opens'],
					'closes' => $hours[ $day ]['closes'],
				);
			}

			$groups[ $key ]['days'][] = $day;
		}

		$specifications = array();

		foreach ( $groups as $group ) {
			$specifications[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => 1 === count( $group['days'] ) ? $group['days'][0] : $group['days'],
				'opens'     => $group['opens'],
				'closes'    => $group['closes'],
			);
		}

		return $specifications;
	}

	private static function get_business_schema(): array {
		$settings = self::get_business_profile();

		if ( ! self::is_business_profile_ready( $settings ) ) {
			return array();
		}

		$home_url = home_url( '/' );
		$schema   = array(
			'@id'       => $home_url . '#identity',
			'@type'     => $settings['business_type'],
			'name'      => $settings['name'],
			'url'       => self::get_business_page_url( $settings ),
			'telephone' => $settings['telephone'],
			'address'   => array_filter(
				array(
					'@type'          => 'PostalAddress',
					'streetAddress'   => $settings['street_address'],
					'addressLocality' => $settings['address_locality'],
					'addressRegion'   => $settings['address_region'],
					'postalCode'      => $settings['postal_code'],
					'addressCountry'  => $settings['address_country'],
				)
			),
		);
		$logo     = self::get_site_logo_data();

		if ( ! empty( $logo['url'] ) ) {
			$schema['logo'] = array_filter(
				array(
					'@id'    => $home_url . '#logo',
					'@type'  => 'ImageObject',
					'height' => $logo['height'],
					'url'    => $logo['url'],
					'width'  => $logo['width'],
				)
			);
		}

		if ( '' !== $settings['latitude'] && '' !== $settings['longitude'] ) {
			$schema['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $settings['latitude'],
				'longitude' => (float) $settings['longitude'],
			);
		}

		$opening_hours = self::get_opening_hours_schema( $settings );

		if ( ! empty( $opening_hours ) ) {
			$schema['openingHoursSpecification'] = $opening_hours;
		}

		$profiles = self::get_social_settings()['profiles'];

		if ( '' !== $settings['gbp_url'] ) {
			$profiles[] = $settings['gbp_url'];
		}

		if ( ! empty( $profiles ) ) {
			$schema['sameAs'] = array_values( array_unique( $profiles ) );
		}

		return $schema;
	}

	/**
	 * Registers the server-rendered business profile block.
	 *
	 * @return void
	 */
	public static function register_business_profile_block(): void {
		$block_dir  = plugin_dir_path( self::FILE ) . 'blocks/business-profile';
		$script_path = $block_dir . '/index.js';

		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			return;
		}

		wp_register_script(
			'erankly-business-profile-editor',
			plugins_url( 'blocks/business-profile/index.js', self::FILE ),
			array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
			self::asset_version( $script_path ),
			true
		);
		wp_set_script_translations(
			'erankly-business-profile-editor',
			'easyrankly'
		);

		register_block_type(
			$block_dir,
			array(
				'render_callback' => array( self::class, 'render_business_profile_block' ),
			)
		);
	}

	private static function normalize_boolean_attribute( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return ! in_array( strtolower( trim( (string) $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
	}

	/**
	 * Renders the dynamic business profile block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_business_profile_block( $attributes ): string {
		return self::render_business_profile( is_array( $attributes ) ? $attributes : array() );
	}

	/**
	 * Renders the business profile shortcode.
	 *
	 * @param array<string, mixed>|string $attributes Shortcode attributes.
	 * @return string
	 */
	public static function render_business_profile_shortcode( $attributes = array() ): string {
		$attributes = shortcode_atts(
			array(
				'show_name'    => 'true',
				'show_address' => 'true',
				'show_phone'   => 'true',
				'show_hours'   => 'true',
				'show_gbp'     => 'true',
			),
			is_array( $attributes ) ? $attributes : array(),
			'easyrankly_business_profile'
		);

		return self::render_business_profile(
			array(
				'showName'    => $attributes['show_name'],
				'showAddress' => $attributes['show_address'],
				'showPhone'   => $attributes['show_phone'],
				'showHours'   => $attributes['show_hours'],
				'showGbp'     => $attributes['show_gbp'],
			)
		);
	}

	private static function render_business_profile( $attributes ): string {
		$settings = self::get_business_profile();

		if ( ! self::is_business_profile_ready( $settings ) ) {
			return '';
		}

		$attributes = wp_parse_args(
			$attributes,
			array(
				'showName'    => true,
				'showAddress' => true,
				'showPhone'   => true,
				'showHours'   => true,
				'showGbp'     => true,
			)
		);

		foreach ( $attributes as $key => $value ) {
			$attributes[ $key ] = self::normalize_boolean_attribute( $value );
		}

		$address_line = trim( $settings['postal_code'] . ' ' . $settings['address_locality'] );

		if ( '' !== $settings['address_region'] ) {
			$address_line .= ' ' . $settings['address_region'];
		}

		$has_hours = false;

		foreach ( $settings['opening_hours'] as $hours ) {
			if ( ! empty( $hours['enabled'] ) ) {
				$has_hours = true;
				break;
			}
		}

		ob_start();
		?>
		<div class="wp-block-easyrankly-business-profile">
			<?php if ( $attributes['showName'] ) : ?>
				<strong class="easyrankly-business-name"><?php echo esc_html( $settings['name'] ); ?></strong>
			<?php endif; ?>
			<?php if ( $attributes['showAddress'] ) : ?>
				<address class="easyrankly-business-address">
					<?php echo esc_html( $settings['street_address'] ); ?><br>
					<?php echo esc_html( $address_line ); ?><br>
					<?php echo esc_html( $settings['address_country'] ); ?>
				</address>
			<?php endif; ?>
			<?php if ( $attributes['showPhone'] ) : ?>
				<p class="easyrankly-business-phone"><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $settings['telephone'] ) ); ?>"><?php echo esc_html( $settings['telephone'] ); ?></a></p>
			<?php endif; ?>
			<?php if ( $attributes['showHours'] && $has_hours ) : ?>
				<dl class="easyrankly-business-hours">
					<?php foreach ( self::get_weekdays() as $day => $label ) : ?>
						<?php $hours = $settings['opening_hours'][ $day ]; ?>
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd><?php echo ! empty( $hours['enabled'] ) ? esc_html( $hours['opens'] . '–' . $hours['closes'] ) : esc_html__( 'Closed', 'easyrankly' ); ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
			<?php if ( $attributes['showGbp'] && '' !== $settings['gbp_url'] ) : ?>
				<p class="easyrankly-business-gbp"><a href="<?php echo esc_url( $settings['gbp_url'] ); ?>"><?php esc_html_e( 'View on Google Maps', 'easyrankly' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
