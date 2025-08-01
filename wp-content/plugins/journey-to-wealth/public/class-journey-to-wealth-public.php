<?php
/**
 * The public-facing functionality of the plugin.
 * This class handles the shortcode and AJAX request for the analyzer tool.
 */
class Journey_To_Wealth_Public {

    private $plugin_name;
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->load_dependencies();

        // Register the modular AJAX endpoint
        add_action('wp_ajax_jtw_fetch_section_data', array($this, 'ajax_fetch_section_data'));
        add_action('wp_ajax_nopriv_jtw_fetch_section_data', array($this, 'ajax_fetch_section_data'));

        // Register the symbol search endpoint
        add_action('wp_ajax_jtw_symbol_search', array($this, 'ajax_symbol_search'));
        
        // Register the peer data endpoint
        add_action('wp_ajax_jtw_fetch_peer_data', array($this, 'ajax_fetch_peer_data'));
        add_action('wp_ajax_nopriv_jtw_fetch_peer_data', array($this, 'ajax_fetch_peer_data'));

        // Register the valuation recalculation endpoint
        add_action('wp_ajax_jtw_recalculate_valuation', array($this, 'ajax_recalculate_valuation'));
        add_action('wp_ajax_nopriv_jtw_recalculate_valuation', array($this, 'ajax_recalculate_valuation'));
    }

    private function load_dependencies() {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/api/class-alpha-vantage-client.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/analysis/models/class-journey-to-wealth-key-metrics-calculator.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/analysis/models/class-journey-to-wealth-dcf-model.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/analysis/models/class-journey-to-wealth-ddm-model.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/analysis/models/class-journey-to-wealth-affo-model.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/analysis/models/class-journey-to-wealth-excess-return-model.php';
    }

    public function enqueue_styles() {
        wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'assets/css/public-styles.css', array(), $this->version, 'all' );
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.5.0/dist/chart.umd.min.js', array(), '4.5.0', true );
        wp_enqueue_script( 'chartjs-adapter-date-fns', 'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js', array('chartjs'), '1.1.0', true );
        wp_enqueue_script( 'chartjs-plugin-datalabels', 'https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.1.0/dist/chartjs-plugin-datalabels.min.js', array('chartjs'), '2.1.0', true );
        
        $script_path = plugin_dir_path( __FILE__ ) . 'assets/js/public-scripts.js';
        $script_version = file_exists($script_path) ? $this->version . '.' . filemtime( $script_path ) : $this->version;
        wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'assets/js/public-scripts.js', array( 'jquery', 'chartjs', 'chartjs-adapter-date-fns', 'chartjs-plugin-datalabels' ), $script_version, true );
        
        $analysis_page_slug = get_option('jtw_analysis_page_slug', 'stock-valuation-analysis');
        $analysis_page_url = site_url( '/' . $analysis_page_slug . '/' );

        wp_localize_script( $this->plugin_name, 'jtw_public_params', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'section_nonce' => wp_create_nonce('jtw_fetch_section_nonce'),
                'peer_nonce' => wp_create_nonce('jtw_fetch_peer_nonce'),
                'recalculate_nonce' => wp_create_nonce('jtw_recalculate_valuation_nonce'),
                'symbol_search_nonce' => wp_create_nonce('jtw_symbol_search_nonce_action'),
                'analysis_page_url' => $analysis_page_url,
                'text_loading' => __('Fetching data...', 'journey-to-wealth'),
                'text_error' => __('An error occurred. Please check the ticker and try again.', 'journey-to-wealth'),
            )
        );
    }

    public function render_header_lookup_shortcode( $atts ) {
        if (!is_user_logged_in()) return '';
        $unique_id = 'jtw-header-lookup-' . uniqid();
        $output = '<div class="jtw-header-lookup-form jtw-header-lookup-container" id="' . esc_attr($unique_id) . '">';
        $output .= '<div class="jtw-input-group-seamless">';
        $output .= '<input type="text" class="jtw-header-ticker-input" placeholder="Search Ticker...">';
        $output .= '<button type="button" class="jtw-header-fetch-button" title="Analyze Stock">';
        $output .= '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
        $output .= '</button>';
        $output .= '</div>';
        $output .= '<div class="jtw-header-search-results"></div>';
        $output .= '</div>';
        return $output;
    }

    public function render_mobile_header_lookup_shortcode( $atts ) {
        if (!is_user_logged_in()) return '';
        $unique_id = 'jtw-mobile-header-lookup-' . uniqid();
        $output = '<div class="jtw-header-lookup-form jtw-mobile-header-lookup-container" id="' . esc_attr($unique_id) . '">';
        $output .= '<div class="jtw-input-group-seamless">';
        $output .= '<input type="text" class="jtw-header-ticker-input" placeholder="Search Ticker...">';
        $output .= '<button type="button" class="jtw-header-fetch-button" title="Analyze Stock">';
        $output .= '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
        $output .= '</button>';
        $output .= '</div>';
        $output .= '<div class="jtw-header-search-results"></div>';
        $output .= '</div>';
        return $output;
    }
    
    public function render_analyzer_layout_shortcode( $atts ) {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to use the stock analyzer.', 'journey-to-wealth') . '</p>';
        }

        $unique_id = 'jtw-analyzer-layout-' . uniqid();
        
        $output = '<div class="jtw-analyzer-wrapper" id="' . esc_attr($unique_id) . '">';
        $output .= '<div id="jtw-main-content-area" class="jtw-main-content-area">';
        
        $url_params = $_GET;
        if ( !isset($url_params['jtw_selected_symbol']) || empty($url_params['jtw_selected_symbol']) ) {
            $output .= '<p class="jtw-initial-prompt">' . esc_html__('Please use the search bar in the header to analyze a stock.', 'journey-to-wealth') . '</p>';
        } else {
            $output .= '<div class="jtw-content-container">';
            
            // Desktop Navigation (will be hidden on mobile)
            $output .= '<nav class="jtw-anchor-nav"><ul>';
            $output .= '<li class="jtw-nav-group jtw-nav-group-single"><a href="#section-overview" class="jtw-anchor-link jtw-nav-major-section active">' . esc_html__('Company Overview', 'journey-to-wealth') . '</a></li>';
            $output .= '<li class="jtw-nav-group"><span class="jtw-nav-major-section">' . esc_html__('Valuation', 'journey-to-wealth') . '</span><div class="jtw-nav-minor-group"><a href="#section-key-metrics-ratios" class="jtw-anchor-link jtw-nav-minor-section">' . esc_html__('Key Metrics & Ratios', 'journey-to-wealth') . '</a><a href="#section-intrinsic-valuation" class="jtw-anchor-link jtw-nav-minor-section">' . esc_html__('Fair Value Analysis', 'journey-to-wealth') . '</a></div></li>';
            $output .= '<li class="jtw-nav-group"><span class="jtw-nav-major-section">' . esc_html__('Past Performance', 'journey-to-wealth') . '</span><div class="jtw-nav-minor-group"><a href="#section-historical-data" class="jtw-anchor-link jtw-nav-minor-section">' . esc_html__('Data Trends', 'journey-to-wealth') . '</a><a href="#section-past-performance" class="jtw-anchor-link jtw-nav-minor-section">' . esc_html__('Visual Trends', 'journey-to-wealth') . '</a></div></li>';
            $output .= '</ul></nav>';
            
            $output .= '<div class="jtw-mobile-dot-nav"></div>';

            $output .= '<main class="jtw-content-main">';
            $output .= '<div id="jtw-currency-notice-placeholder"></div>';
            $output .= '<div class="jtw-major-content-group" id="major-section-overview"><h2>' . esc_html__('Company Overview', 'journey-to-wealth') . '</h2><div id="section-overview" class="jtw-content-section-placeholder" data-section="overview"></div></div>';
            $output .= '<div class="jtw-major-content-group" id="major-section-valuation"><h2>' . esc_html__('Valuation', 'journey-to-wealth') . '</h2><div id="section-key-metrics-ratios" class="jtw-content-section-placeholder" data-section="key-metrics-ratios"></div><div id="section-intrinsic-valuation" class="jtw-content-section-placeholder" data-section="intrinsic-valuation"></div></div>';
            $output .= '<div class="jtw-major-content-group" id="major-section-performance"><h2>' . esc_html__('Past Performance', 'journey-to-wealth') . '</h2><div id="section-historical-data" class="jtw-content-section-placeholder" data-section="historical-data"></div><div id="section-past-performance" class="jtw-content-section-placeholder" data-section="past-performance"></div></div>';
            $output .= '</main></div>';
        }
        
        $output .= '</div></div>';
        return $output;
    }

    public function ajax_symbol_search() {
        check_ajax_referer('jtw_symbol_search_nonce_action', 'jtw_symbol_search_nonce');
        $keywords = isset($_POST['keywords']) ? sanitize_text_field($_POST['keywords']) : '';
        if (empty($keywords)) { wp_send_json_error(['matches' => []]); return; }
        $api_key = get_option('jtw_av_api_key');
        if (empty($api_key)) { wp_send_json_error(['message' => 'API Key not configured.']); return; }
        $av_client = new Alpha_Vantage_Client($api_key);
        $results = $av_client->search_symbols($keywords);
        if (is_wp_error($results) || empty($results)) { wp_send_json_success(['matches' => []]); return; }
        $matches = array_map(function($item) {
            return [ 'ticker' => $item['1. symbol'], 'name' => $item['2. name'], 'exchange' => $item['4. region'], 'locale' => strtolower(substr($item['8. currency'], 0, 2)), 'icon_url' => '', ];
        }, $results);
        wp_send_json_success(['matches' => array_slice($matches, 0, 3)]);
    }

    private function convert_financial_data(&$report_data, $exchange_rate) {
        if ($exchange_rate == 1.0 || !is_array($report_data)) { return; }
        $report_types = ['annualReports', 'quarterlyReports', 'annualEarnings', 'quarterlyEarnings'];
        $keys_to_skip = ['fiscalDateEnding', 'commonStockSharesOutstanding'];
        foreach ($report_types as $type) {
            if (isset($report_data[$type])) {
                foreach ($report_data[$type] as &$report) {
                    foreach ($report as $field => &$value) {
                        if (is_numeric($value) && !in_array($field, $keys_to_skip)) { $value = (float)$value * $exchange_rate; }
                    }
                }
            }
        }
    }

    private function get_and_prepare_company_data($ticker) {
        $transient_key = 'jtw_data_' . $ticker;
        $company_data = get_transient($transient_key);
        if (false === $company_data) {
            $av_client = new Alpha_Vantage_Client(get_option('jtw_av_api_key'));
            $overview = $av_client->get_company_overview($ticker);
            if(is_wp_error($overview) || !isset($overview['Symbol'])) { return new WP_Error('api_error', 'Could not retrieve company overview.'); }
            $income_statement = $av_client->get_income_statement($ticker);
            $balance_sheet = $av_client->get_balance_sheet($ticker);
            $cash_flow = $av_client->get_cash_flow_statement($ticker);
            $earnings = $av_client->get_earnings_data($ticker);
            $earnings_estimates = $av_client->get_earnings_estimates($ticker);
            $quote = $av_client->get_global_quote($ticker);
            $daily_data = $av_client->get_daily_adjusted($ticker);
            $treasury_yield = $av_client->get_treasury_yield();
            $original_currency = 'USD';
            if (isset($income_statement['annualReports'][0]['reportedCurrency'])) {
                $currency_value = $income_statement['annualReports'][0]['reportedCurrency'];
                if (!empty($currency_value) && strcasecmp(trim($currency_value), 'none') !== 0) { $original_currency = strtoupper(trim($currency_value)); }
            }
            $exchange_rate = 1.0;
            $currency_notice = '';
            if ($original_currency !== 'USD') {
                $rate_data = $av_client->get_currency_exchange_rate($original_currency, 'USD');
                if (!is_wp_error($rate_data) && isset($rate_data['Realtime Currency Exchange Rate']['5. Exchange Rate'])) {
                    $exchange_rate = (float)$rate_data['Realtime Currency Exchange Rate']['5. Exchange Rate'];
                    $currency_notice = sprintf('<div class="jtw-currency-notice notice notice-info inline"><p><strong>Note:</strong> All financial data has been converted from %s to USD at a rate of 1 %s = %s USD.</p></div>', esc_html($original_currency), esc_html($original_currency), esc_html(number_format($exchange_rate, 4)));
                    $this->convert_financial_data($income_statement, $exchange_rate);
                    $this->convert_financial_data($balance_sheet, $exchange_rate);
                    $this->convert_financial_data($cash_flow, $exchange_rate);
                    $this->convert_financial_data($earnings, $exchange_rate);
                } else {
                    return new WP_Error('currency_error', 'Could not retrieve currency exchange rate.');
                }
            }
            $company_data = compact('income_statement', 'balance_sheet', 'cash_flow', 'earnings', 'earnings_estimates', 'overview', 'quote', 'daily_data', 'treasury_yield', 'currency_notice');
            set_transient($transient_key, $company_data, HOUR_IN_SECONDS);
        }
        return $company_data;
    }

    public function ajax_fetch_section_data() {
        check_ajax_referer('jtw_fetch_section_nonce', 'nonce');
        $ticker = isset($_POST['ticker']) ? sanitize_text_field(strtoupper($_POST['ticker'])) : '';
        $section = isset($_POST['section']) ? sanitize_key($_POST['section']) : '';
        if (empty($ticker) || empty($section)) { wp_send_json_error(['message' => 'Missing parameters.']); return; }

        if (class_exists('MeprUser')) {
            $user_id = get_current_user_id();
            $mepr_user = new MeprUser($user_id);
            if (!$user_id || !$mepr_user->is_active()) {
                $upgrade_url = get_permalink(get_option('mepr_account_page_id'));
                $html = '<div class="jtw-paywall"><h4>' . esc_html__('Upgrade Required', 'journey-to-wealth') . '</h4><p>' . esc_html__('This section is available for premium members.', 'journey-to-wealth') . '</p><a href="' . esc_url($upgrade_url) . '" class="jtw-button-primary">' . esc_html__('Upgrade Now', 'journey-to-wealth') . '</a></div>';
                wp_send_json_success(['html' => $html, 'paywall' => true]); return;
            }
        } elseif (!is_user_logged_in()) {
            $html = '<div class="jtw-paywall"><h4>' . esc_html__('Login Required', 'journey-to-wealth') . '</h4><p>' . esc_html__('You must be logged in to view this content.', 'journey-to-wealth') . '</p><a href="' . wp_login_url(get_permalink()) . '" class="jtw-button-primary">' . esc_html__('Login', 'journey-to-wealth') . '</a></div>';
            wp_send_json_success(['html' => $html, 'paywall' => true]); return;
        }
        
        $company_data = $this->get_and_prepare_company_data($ticker);
        if (is_wp_error($company_data)) { wp_send_json_error(['message' => $company_data->get_error_message()]); return; }
        $html = '';
        $json_response = [];
        if ($section === 'overview' && !empty($company_data['currency_notice'])) { $json_response['currency_notice'] = $company_data['currency_notice']; }

        switch ($section) {
            case 'overview':
                if (!is_wp_error($company_data['overview']) && !empty($company_data['overview']['Symbol'])) {
                    $this->store_and_map_discovered_company($ticker, $company_data['overview']['Industry'], $company_data['overview']['Sector']);
                    $html = $this->build_overview_section_html($company_data['overview'], $company_data['quote']);
                }
                break;
            case 'historical-data':
                $html = $this->build_historical_data_section_html($this->process_historical_table_data($company_data));
                break;
            case 'past-performance':
                $html = $this->build_past_performance_section_html($this->process_av_historical_data($company_data['daily_data'], $company_data['income_statement'], $company_data['balance_sheet'], $company_data['cash_flow'], $company_data['earnings']));
                break;
            case 'key-metrics-ratios':
                $primary_metrics = $this->get_company_key_metrics($company_data);
                $html = $this->build_key_metrics_ratios_section_html($ticker, $primary_metrics);
                break;
            case 'intrinsic-valuation':
                $latest_price = !is_wp_error($company_data['quote']) ? (float)($company_data['quote']['05. price'] ?? 0) : 0;
                $valuation_data = $this->get_valuation_results($company_data, $latest_price);
                $valuation_summary = [ 'current_price' => $latest_price, 'fair_value' => 0, 'percentage_diff' => 0 ];
                $valid_models = [];
                foreach ($valuation_data as $result) { if (!is_wp_error($result) && isset($result['intrinsic_value_per_share'])) { $valid_models[] = $result['intrinsic_value_per_share']; } }
                if (!empty($valid_models)) {
                    $valuation_summary['fair_value'] = array_sum($valid_models) / count($valid_models);
                    if ($latest_price > 0 && $valuation_summary['fair_value'] > 0) { $valuation_summary['percentage_diff'] = (($latest_price - $valuation_summary['fair_value']) / $valuation_summary['fair_value']) * 100; }
                }
                $html = $this->build_intrinsic_valuation_section_html($valuation_data, $valuation_summary, $company_data['overview'], $company_data['income_statement']);
                break;
        }
        if (empty($html)) { wp_send_json_error(['message' => 'Could not generate content for this section.']); } 
        else { $json_response['html'] = $html; wp_send_json_success($json_response); }
    }

    public function ajax_fetch_peer_data() {
        check_ajax_referer('jtw_fetch_peer_nonce', 'nonce');
        $primary_ticker = isset($_POST['ticker']) ? sanitize_text_field(strtoupper($_POST['ticker'])) : '';
        if (empty($primary_ticker)) { wp_send_json_error(['message' => 'Missing ticker.']); return; }
    
        $av_client = new Alpha_Vantage_Client(get_option('jtw_av_api_key'));
    
        // Get primary company's name first
        $primary_overview = $av_client->get_company_overview($primary_ticker);
        if (is_wp_error($primary_overview) || !isset($primary_overview['Name'])) {
            wp_send_json_error(['message' => 'Could not retrieve primary company data.']);
            return;
        }
        $primary_company_name = $primary_overview['Name'];
    
        global $wpdb;
        $mapping_table = $wpdb->prefix . 'jtw_company_mappings';
    
        // Find Damodaran industries for the primary ticker
        $damodaran_ids = $wpdb->get_col($wpdb->prepare("SELECT damodaran_industry_id FROM $mapping_table WHERE ticker = %s", $primary_ticker));
        if (empty($damodaran_ids)) {
            wp_send_json_error(['message' => 'No industry mapping found. Peer comparison is unavailable.']);
            return;
        }
    
        // Find all tickers with the same Damodaran industries
        $id_placeholders = implode(',', array_fill(0, count($damodaran_ids), '%d'));
        $query = $wpdb->prepare("SELECT DISTINCT ticker FROM $mapping_table WHERE damodaran_industry_id IN ($id_placeholders) AND ticker != %s", array_merge($damodaran_ids, [$primary_ticker]));
        $all_peers = $wpdb->get_col($query);
    
        if (empty($all_peers)) {
            wp_send_json_error(['message' => 'No direct peers found based on industry mapping.']);
            return;
        }
    
        // Fetch market cap and name for all peers to find the largest, non-duplicate companies
        $peers_with_marketcap = [];
        foreach ($all_peers as $peer_ticker) {
            $transient_key = 'jtw_overview_' . $peer_ticker;
            $overview = get_transient($transient_key);
            if (false === $overview) {
                $overview = $av_client->get_company_overview($peer_ticker);
                if (!is_wp_error($overview) && isset($overview['MarketCapitalization'])) {
                    set_transient($transient_key, $overview, DAY_IN_SECONDS);
                }
            }
    
            if (!is_wp_error($overview) && isset($overview['MarketCapitalization'], $overview['Name']) && is_numeric($overview['MarketCapitalization'])) {
                // Check for same company name (e.g., GOOG vs GOOGL)
                similar_text(strtolower($primary_company_name), strtolower($overview['Name']), $percent);
                if ($percent < 95.0) { // If names are less than 95% similar, consider them different companies
                    $peers_with_marketcap[$peer_ticker] = (float)$overview['MarketCapitalization'];
                }
            }
        }
    
        if (empty($peers_with_marketcap)) {
            wp_send_json_error(['message' => 'No suitable peers found after filtering.']);
            return;
        }
    
        arsort($peers_with_marketcap); // Sort by market cap descending
        $top_peers = array_slice(array_keys($peers_with_marketcap), 0, 2);
    
        if (empty($top_peers)) {
            wp_send_json_error(['message' => 'Could not retrieve data for any potential peers.']);
            return;
        }
    
        $peer_metrics_data = [];
        foreach ($top_peers as $peer_ticker) {
            $company_data = $this->get_and_prepare_company_data($peer_ticker);
            if (!is_wp_error($company_data)) {
                $peer_metrics_data[$peer_ticker] = $this->get_company_key_metrics($company_data);
            }
        }
    
        if (empty($peer_metrics_data)) {
            wp_send_json_error(['message' => 'Failed to fetch detailed metrics for top peers.']);
            return;
        }
    
        wp_send_json_success($peer_metrics_data);
    }

    public function ajax_recalculate_valuation() {
        check_ajax_referer('jtw_recalculate_valuation_nonce', 'nonce');
    
        $ticker = isset($_POST['ticker']) ? sanitize_text_field(strtoupper($_POST['ticker'])) : '';
        $assumptions = isset($_POST['assumptions']) ? $_POST['assumptions'] : [];
    
        if (empty($ticker) || empty($assumptions)) {
            wp_send_json_error(['message' => 'Missing parameters for recalculation.']);
            return;
        }
    
        // Fetch the base data required for valuation
        $company_data = $this->get_and_prepare_company_data($ticker);
        if (is_wp_error($company_data)) {
            wp_send_json_error(['message' => $company_data->get_error_message()]);
            return;
        }
    
        // Ensure authoritative shares outstanding is used for recalculation
        $balance_sheet = $company_data['balance_sheet'];
        if (!is_wp_error($balance_sheet) && isset($balance_sheet['annualReports'][0]['commonStockSharesOutstanding'])) {
            $authoritative_shares = (float)$balance_sheet['annualReports'][0]['commonStockSharesOutstanding'];
            if ($authoritative_shares > 0) {
                $company_data['overview']['SharesOutstanding'] = $authoritative_shares;
            }
        }

        $erp_decimal = (float) get_option('jtw_erp_setting', '5.0') / 100;
        $tax_rate_decimal = (float) get_option('jtw_tax_rate_setting', '21.0') / 100;
        $beta_details = $this->calculate_levered_beta($ticker, $company_data['balance_sheet'], $company_data['overview']['MarketCapitalization'], $tax_rate_decimal);
        $levered_beta = $beta_details['levered_beta'];
    
        $dcf_model = new Journey_To_Wealth_DCF_Model($erp_decimal, $levered_beta);
    
        $results = [];
    
        foreach (['bear', 'base', 'bull'] as $case) {
            if (isset($assumptions[$case])) {
                $case_assumptions = $assumptions[$case];
                
                // Prepare custom assumptions for the model
                $custom_assumptions = [
                    'initial_growth_rate' => (float)$case_assumptions['revGrowth'] / 100,
                    'initial_fcfe' => (float)$case_assumptions['initialFcfe']
                ];
    
                // Recalculate using the model
                $result = $dcf_model->calculate(
                    $company_data['overview'], $company_data['income_statement'], $company_data['balance_sheet'],
                    $company_data['cash_flow'], $company_data['treasury_yield'], $company_data['earnings_estimates'],
                    (float)($company_data['quote']['05. price'] ?? 0),
                    $beta_details,
                    $custom_assumptions // Pass the custom assumptions here
                );
    
                $fair_value = !is_wp_error($result) ? $result['intrinsic_value_per_share'] : 0;
                
                // Calculate Buy Price based on desired return
                $buy_price = 0;
                $desired_return = (float)($case_assumptions['desiredReturn'] ?? 15) / 100;
                if ($fair_value > 0 && $desired_return > 0) {
                    // Simplified calculation: FV = P * (1+r)^n => P = FV / (1+r)^n
                    // Assuming a 10-year horizon to match the DCF
                    $buy_price = $fair_value / pow(1 + $desired_return, 10);
                }
    
                $results[$case] = [
                    'fair_value' => $fair_value,
                    'buy_price' => $buy_price
                ];
            }
        }
    
        wp_send_json_success($results);
    }

    private function get_company_key_metrics($company_data) {
        if (is_wp_error($company_data)) return [];

        $overview = $company_data['overview'];
        $quote = $company_data['quote'];
        $stock_price = !is_wp_error($quote) ? (float)($quote['05. price'] ?? 0) : 0;
        $trailing_pe_ratio = isset($overview['PERatio']) && $overview['PERatio'] !== 'None' ? (float)$overview['PERatio'] : 'N/A';
        $trailing_eps = isset($overview['EPS']) && $overview['EPS'] !== 'None' ? (float)$overview['EPS'] : 'N/A';
        $forward_pe_ratio = isset($overview['ForwardPE']) && $overview['ForwardPE'] !== 'None' ? (float)$overview['ForwardPE'] : 'N/A';
        
        $key_metrics = [
            'stockPrice' => $stock_price,
            'trailingEps' => $trailing_eps,
            'trailingPeRatio' => $trailing_pe_ratio,
            'forwardPeRatio' => $forward_pe_ratio,
            'pbRatio' => isset($overview['PriceToBookRatio']) && $overview['PriceToBookRatio'] !== 'None' ? (float)$overview['PriceToBookRatio'] : 'N/A',
            'psRatio' => isset($overview['PriceToSalesRatioTTM']) && $overview['PriceToSalesRatioTTM'] !== 'None' ? (float)$overview['PriceToSalesRatioTTM'] : 'N/A',
            'evToRevenue' => isset($overview['EVToRevenue']) && $overview['EVToRevenue'] !== 'None' ? (float)$overview['EVToRevenue'] : 'N/A',
            'evToEbitda' => isset($overview['EVToEBITDA']) && $overview['EVToEBITDA'] !== 'None' ? (float)$overview['EVToEBITDA'] : 'N/A',
            'grossMargin' => isset($overview['GrossProfitTTM']) && is_numeric($overview['GrossProfitTTM']) && (float)$overview['RevenueTTM'] > 0 ? ((float)$overview['GrossProfitTTM'] / (float)$overview['RevenueTTM']) * 100 : 'N/A',
            'netMargin' => isset($overview['ProfitMargin']) && is_numeric($overview['ProfitMargin']) ? (float)$overview['ProfitMargin'] * 100 : 'N/A',
        ];

        $dcf_model_for_growth = new Journey_To_Wealth_DCF_Model();
        $beta_details = $this->calculate_levered_beta($overview['Symbol'], $company_data['balance_sheet'], $overview['MarketCapitalization'], 0.21);
        $risk_free_rate = $dcf_model_for_growth->calculate_average_risk_free_rate($company_data['treasury_yield']);
        $growth_rate_info = $dcf_model_for_growth->get_initial_growth_rate($company_data['earnings_estimates'], $company_data['income_statement']['annualReports'], $risk_free_rate, $beta_details);
        
        $final_growth_rate = $growth_rate_info['rate'] * 100;
        $dividend_yield_percent = isset($overview['DividendYield']) && is_numeric($overview['DividendYield']) ? (float)$overview['DividendYield'] * 100 : 0;
        
        $key_metrics['pegRatio'] = (is_numeric($trailing_pe_ratio) && $trailing_pe_ratio > 0 && $final_growth_rate > 0) ? ($trailing_pe_ratio / $final_growth_rate) : 'N/A';
        $key_metrics['pegyRatio'] = (is_numeric($trailing_pe_ratio) && ($final_growth_rate + $dividend_yield_percent) > 0) ? ($trailing_pe_ratio / ($final_growth_rate + $dividend_yield_percent)) : 'N/A';
        $key_metrics['defaultGrowth'] = $final_growth_rate;
        $key_metrics['dividendYield'] = $dividend_yield_percent;

        $growth_data = $this->calculate_growth_metrics($company_data);
        return array_merge($key_metrics, $growth_data);
    }

    private function calculate_growth_metrics($company_data) {
        $growth = [
            'ttmEpsGrowth' => 'N/A',
            'currentYearEpsGrowth' => 'N/A',
            'nextYearEpsGrowth' => 'N/A',
            'ttmRevenueGrowth' => 'N/A',
            'currentYearRevenueGrowth' => 'N/A',
            'nextYearRevenueGrowth' => 'N/A',
        ];
    
        // TTM Growth Calculations
        $earnings = $company_data['earnings']['annualEarnings'] ?? [];
        $income = $company_data['income_statement']['annualReports'] ?? [];
        $overview = $company_data['overview'];
    
        if (count($earnings) >= 2) {
            $ttm_eps = (float)($overview['EPS'] ?? 0);
            $prev_eps = (float)($earnings[0]['reportedEPS'] ?? 0);
            if ($prev_eps != 0) { // Avoid division by zero
                $growth['ttmEpsGrowth'] = (($ttm_eps / $prev_eps) - 1) * 100;
            }
        }
    
        if (count($income) >= 2) {
            $ttm_revenue = (float)($overview['RevenueTTM'] ?? 0);
            $prev_revenue = (float)($income[0]['totalRevenue'] ?? 0);
            if ($prev_revenue > 0) {
                $growth['ttmRevenueGrowth'] = (($ttm_revenue / $prev_revenue) - 1) * 100;
            }
        }
    
        // Analyst Estimate Growth Calculations
        $estimates_data = $company_data['earnings_estimates'];
        if (!is_wp_error($estimates_data) && !empty($estimates_data['estimates'])) {
            $estimates = $estimates_data['estimates'];
            $annual_estimates = array_filter($estimates, function($e) {
                return isset($e['horizon']) && ($e['horizon'] === 'current fiscal year' || $e['horizon'] === 'next fiscal year');
            });
    
            usort($annual_estimates, function($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });
    
            $next_year_estimate = null;
            $current_year_estimate = null;
    
            foreach ($annual_estimates as $estimate) {
                if ($next_year_estimate === null && $estimate['horizon'] === 'next fiscal year') {
                    $next_year_estimate = $estimate;
                }
                if ($current_year_estimate === null && $estimate['horizon'] === 'current fiscal year') {
                    $current_year_estimate = $estimate;
                }
                if ($next_year_estimate && $current_year_estimate) break;
            }
    
            if ($current_year_estimate) {
                $current_year_eps = (float)($current_year_estimate['eps_estimate_average'] ?? 0);
                $current_year_revenue = (float)($current_year_estimate['revenue_estimate_average'] ?? 0);
    
                if ($ttm_eps != 0) $growth['currentYearEpsGrowth'] = (($current_year_eps / $ttm_eps) - 1) * 100;
                if ($ttm_revenue > 0) $growth['currentYearRevenueGrowth'] = (($current_year_revenue / $ttm_revenue) - 1) * 100;
    
                if ($next_year_estimate) {
                    $next_year_eps = (float)($next_year_estimate['eps_estimate_average'] ?? 0);
                    $next_year_revenue = (float)($next_year_estimate['revenue_estimate_average'] ?? 0);
    
                    if ($current_year_eps != 0) $growth['nextYearEpsGrowth'] = (($next_year_eps / $current_year_eps) - 1) * 100;
                    if ($current_year_revenue > 0) $growth['nextYearRevenueGrowth'] = (($next_year_revenue / $current_year_revenue) - 1) * 100;
                }
            }
        }
    
        return $growth;
    }

    private function get_valuation_results($company_data, $latest_price) {
        $overview = $company_data['overview']; $income_statement = $company_data['income_statement'];
        $balance_sheet = $company_data['balance_sheet']; $cash_flow = $company_data['cash_flow'];
        $earnings = $company_data['earnings']; $treasury_yield = $company_data['treasury_yield'];
        $daily_data = $company_data['daily_data'];
        $earnings_estimates = $company_data['earnings_estimates'];
        $valuation_data = [];
        $erp_decimal = (float) get_option('jtw_erp_setting', '5.0') / 100;
        $tax_rate_decimal = (float) get_option('jtw_tax_rate_setting', '21.0') / 100;
        
        if (!is_wp_error($balance_sheet) && isset($balance_sheet['annualReports'][0]['commonStockSharesOutstanding'])) {
            $authoritative_shares = (float)$balance_sheet['annualReports'][0]['commonStockSharesOutstanding'];
            if ($authoritative_shares > 0) { $overview['SharesOutstanding'] = $authoritative_shares; }
        }
        $beta_details = $this->calculate_levered_beta($overview['Symbol'], $balance_sheet, $overview['MarketCapitalization'], $tax_rate_decimal);
        $levered_beta = $beta_details['levered_beta'];
        $industry_upper = strtoupper($overview['Industry']); $sector_upper = strtoupper($overview['Sector']);
        $is_reit = strpos($industry_upper, 'REIT') !== false || strpos($sector_upper, 'REAL ESTATE') !== false;
        $is_bank = strpos($industry_upper, 'BANK') !== false;
        $is_insurance = strpos($industry_upper, 'INSURANCE') !== false;
        $is_financial_services = strpos($sector_upper, 'FINANCIAL SERVICES') !== false;
    
        if ($is_reit) {
            $model = new Journey_To_Wealth_AFFO_Model($erp_decimal, $levered_beta);
            $result = $model->calculate($overview, $income_statement, $cash_flow, $treasury_yield, $latest_price, $beta_details);
            if (!is_wp_error($result)) { $valuation_data['AFFO Model'] = $result; }
        } elseif ($is_bank || $is_insurance || $is_financial_services) {
            $model = new Journey_To_Wealth_Excess_Return_Model($erp_decimal, $levered_beta);
            $result = $model->calculate($overview, $income_statement, $balance_sheet, $treasury_yield, $latest_price, $beta_details);
            if (!is_wp_error($result)) { $valuation_data['Excess Return Model'] = $result; }
        } else {
            $dcf_model = new Journey_To_Wealth_DCF_Model($erp_decimal, $levered_beta);
            $dcf_result = $dcf_model->calculate($overview, $income_statement, $balance_sheet, $cash_flow, $treasury_yield, $earnings_estimates, $latest_price, $beta_details);
            if (!is_wp_error($dcf_result)) { $valuation_data['DCF Model'] = $dcf_result; }
        }
    
        if (empty($valuation_data) && isset($overview['DividendPerShare']) && (float)$overview['DividendPerShare'] > 0) {
            $ddm_model = new Journey_To_Wealth_DDM_Model($erp_decimal, $levered_beta);
            $ddm_result = $ddm_model->calculate($overview, $treasury_yield, $latest_price, $daily_data, $beta_details);
            if (!is_wp_error($ddm_result)) { $valuation_data['Dividend Discount Model'] = $ddm_result; }
        }
        return $valuation_data;
    }
    
    private function store_and_map_discovered_company($ticker, $industry_name, $sector_name) {
        if (empty($ticker) || empty($industry_name)) { return; }
        $discovered = get_option('jtw_discovered_companies', []);
        if (!is_array($discovered)) { $discovered = []; }
        if (!array_key_exists($ticker, $discovered)) {
            $discovered[$ticker] = $industry_name;
            update_option('jtw_discovered_companies', $discovered);
            $this->auto_map_new_company($ticker, $industry_name, $sector_name, $discovered);
        }
    }

    private function auto_map_new_company($new_ticker, $av_industry, $av_sector, $all_discovered_companies) {
        global $wpdb;
        $mapping_table = $wpdb->prefix . 'jtw_company_mappings';
        $beta_table = $wpdb->prefix . 'jtw_industry_betas';

        $existing_mapping_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $mapping_table WHERE ticker = %s", $new_ticker));
        if ($existing_mapping_count > 0) {
            return;
        }

        $template_tickers = [];
        $similarity_threshold = 80.0; 

        foreach ($all_discovered_companies as $ticker => $industry) {
            if ($ticker === $new_ticker) {
                continue;
            }
            
            similar_text(strtolower($av_industry), strtolower($industry), $percent);

            if ($percent >= $similarity_threshold) {
                $has_mappings = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $mapping_table WHERE ticker = %s", $ticker));
                if ($has_mappings > 0) {
                    $template_tickers[] = $ticker;
                }
            }
        }

        if (!empty($template_tickers)) {
            $placeholders = implode(', ', array_fill(0, count($template_tickers), '%s'));
            $aggregated_damodaran_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT damodaran_industry_id FROM $mapping_table WHERE ticker IN ($placeholders) LIMIT 2",
                $template_tickers
            ));

            if (!empty($aggregated_damodaran_ids)) {
                foreach ($aggregated_damodaran_ids as $dam_id) {
                    $wpdb->insert(
                        $mapping_table,
                        ['ticker' => $new_ticker, 'damodaran_industry_id' => $dam_id],
                        ['%s', '%d']
                    );
                }
                return;
            }
        }

        $av_industry_words = preg_split('/[\s,\/-]+/', strtolower($av_industry));
        $stop_words = ['and', 'or', 'the', 'of', 'in', 'a', 'an', 'etc', 'other'];
        $av_industry_words = array_diff($av_industry_words, $stop_words);
        $av_industry_words = array_filter($av_industry_words);

        $damodaran_industries = $wpdb->get_results("SELECT id, industry_name FROM $beta_table");
        $industry_groups = [
            'Financials' => ['Bank', 'Brokerage', 'Insurance', 'Financial Svcs', 'Investments'],
            'Technology' => ['Software', 'Computer', 'Semiconductor', 'Telecom', 'Internet', 'Electronics'],
            'Healthcare' => ['Healthcare', 'Hospitals', 'Drug', 'Biotechnology', 'Medical'],
            'Consumer Cyclical' => ['Retail', 'Apparel', 'Auto', 'Hotel', 'Gaming', 'Recreation', 'Restaurant', 'Furnishings'],
            'Consumer Defensive' => ['Food', 'Beverage', 'Household Products', 'Tobacco'],
            'Industrials' => ['Aerospace', 'Defense', 'Building Materials', 'Machinery', 'Engineering', 'Construction', 'Air Transport', 'Transportation', 'Shipbuilding'],
            'Energy' => ['Oil', 'Gas', 'Energy', 'Coal', 'Pipeline'],
            'Materials' => ['Chemical', 'Metals', 'Mining', 'Paper', 'Forest Products', 'Rubber', 'Steel'],
            'Real Estate' => ['Real Estate', 'R.E.I.T.'],
            'Utilities' => ['Utility', 'Power'],
            'Services' => ['Advertising', 'Business & Consumer Svcs', 'Publishing', 'Education', 'Entertainment', 'Information Services', 'Office Equipment'],
        ];

        $group_scores = array_fill_keys(array_keys($industry_groups), 0);
        foreach ($damodaran_industries as $dam_industry) {
            $dam_industry_words = preg_split('/[\s,\/-]+/', strtolower($dam_industry->industry_name));
            $dam_industry_words = array_diff($dam_industry_words, $stop_words);
            $dam_industry_words = array_filter($dam_industry_words);
            $matches = count(array_intersect($av_industry_words, $dam_industry_words));
            if ($matches > 0) {
                foreach ($industry_groups as $group_name => $keywords) {
                    foreach ($keywords as $keyword) {
                        if (stripos($dam_industry->industry_name, $keyword) !== false) {
                            $group_scores[$group_name] += $matches;
                            break 2;
                        }
                    }
                }
            }
        }

        arsort($group_scores);
        $best_group_name = key($group_scores);
        $best_score = reset($group_scores);

        $damodaran_group_to_map = null;
        if ($best_score > 0) {
            $damodaran_group_to_map = $best_group_name;
        } else {
            $av_sector_to_group_map = [
                'TECHNOLOGY' => 'Technology', 'FINANCIAL SERVICES' => 'Financials', 'HEALTHCARE' => 'Healthcare',
                'CONSUMER CYCLICAL' => 'Consumer Cyclical', 'CONSUMER DEFENSIVE' => 'Consumer Defensive', 'INDUSTRIALS' => 'Industrials',
                'ENERGY' => 'Energy', 'BASIC MATERIALS' => 'Materials', 'REAL ESTATE' => 'Real Estate', 'UTILITIES' => 'Utilities',
                'COMMUNICATION SERVICES' => 'Services',
            ];
            $damodaran_group_to_map = $av_sector_to_group_map[strtoupper($av_sector)] ?? null;
        }

        if (!$damodaran_group_to_map) return;

        $keywords_to_map = $industry_groups[$damodaran_group_to_map] ?? [];
        if (empty($keywords_to_map)) return;

        $where_clauses = [];
        foreach ($keywords as $keyword) {
            $where_clauses[] = $wpdb->prepare("industry_name LIKE %s", '%' . $wpdb->esc_like($keyword) . '%');
        }
        $damodaran_ids_to_map = $wpdb->get_col("SELECT id FROM $beta_table WHERE " . implode(' OR ', $where_clauses) . " LIMIT 2");

        if (!empty($damodaran_ids_to_map)) {
            foreach ($damodaran_ids_to_map as $dam_id) {
                $wpdb->insert( $mapping_table, [ 'ticker' => $new_ticker, 'damodaran_industry_id' => $dam_id ], ['%s', '%d'] );
            }
        }
    }

    private function calculate_levered_beta($ticker, $balance_sheet, $market_cap, $tax_rate) {
        global $wpdb;
        $debug_data = [ 'levered_beta' => 1.0, 'unlevered_beta_avg' => null, 'debt_to_equity' => null, 'tax_rate' => $tax_rate, 'mapped_damodaran_industries' => [], 'beta_source' => 'Default' ];
        $mapping_table = $wpdb->prefix . 'jtw_company_mappings';
        $beta_table = $wpdb->prefix . 'jtw_industry_betas';
        $unlevered_betas = $wpdb->get_col($wpdb->prepare( "SELECT b.unlevered_beta FROM $mapping_table as m JOIN $beta_table as b ON m.damodaran_industry_id = b.id WHERE m.ticker = %s", $ticker ));
        if (empty($unlevered_betas)) { return $debug_data; }
        $debug_data['mapped_damodaran_industries'] = $wpdb->get_col($wpdb->prepare( "SELECT b.industry_name FROM $mapping_table as m JOIN $beta_table as b ON m.damodaran_industry_id = b.id WHERE m.ticker = %s", $ticker ));
        $average_unlevered_beta = array_sum($unlevered_betas) / count($unlevered_betas);
        $debug_data['unlevered_beta_avg'] = $average_unlevered_beta;
        $debug_data['levered_beta'] = $average_unlevered_beta;
        $debug_data['beta_source'] = 'Calculated from Industry Beta';
        if (is_wp_error($balance_sheet) || empty($balance_sheet['annualReports'])) { return $debug_data; }
        $latest_report = $balance_sheet['annualReports'][0];
        $total_debt = (float)($latest_report['shortTermDebt'] ?? 0) + (float)($latest_report['longTermDebtNoncurrent'] ?? 0);
        if ($market_cap > 0) {
            $debt_to_equity = $total_debt / $market_cap;
            $debug_data['debt_to_equity'] = $debt_to_equity;
            $levered_beta = 0.33 + ((0.66 * $average_unlevered_beta) * (1 + (1 - $tax_rate) * $debt_to_equity));
            $debug_data['unconstrained_levered_beta'] = $levered_beta;
            $debug_data['relevered_beta_calc'] = '0.33 + [(0.66 * ' . number_format($average_unlevered_beta, 3) . ') * (1 + (1 - ' . number_format($tax_rate * 100, 1) . '%) * ' . number_format($debt_to_equity, 3) . ')]';
            $levered_beta = max(0.8, min(2.0, $levered_beta));
            $debug_data['levered_beta'] = $levered_beta;
            $debug_data['beta_source'] = 'Re-levered from Industry Beta (capped 0.8-2.0)';
        }
        return $debug_data;
    }
    
    private function process_av_historical_data($daily_data, $income_statement, $balance_sheet, $cash_flow, $earnings) {
        $master_labels_annual = $this->get_master_labels([$income_statement, $balance_sheet, $cash_flow, $earnings], 'annual', 20);
        $master_labels_quarterly = $this->get_master_labels([$income_statement, $balance_sheet, $cash_flow, $earnings], 'quarterly', 16);
        $annual = [ 'price' => $this->process_av_price_data($daily_data), 'revenue' => $this->extract_av_financial_data($income_statement, 'totalRevenue', 'annual', $master_labels_annual), 'net_income' => $this->extract_av_financial_data($income_statement, 'netIncome', 'annual', $master_labels_annual), 'ebitda' => $this->extract_av_financial_data($income_statement, 'ebitda', 'annual', $master_labels_annual), 'fcf' => $this->extract_av_fcf_data($cash_flow, 'annual', $master_labels_annual), 'cash_and_debt' => $this->extract_av_cash_and_debt_data($balance_sheet, 'annual', $master_labels_annual), 'dividend' => $this->aggregate_av_dividend_data($daily_data, 'annual', $master_labels_annual), 'shares_outstanding' => $this->extract_av_financial_data($balance_sheet, 'commonStockSharesOutstanding', 'annual', $master_labels_annual), 'expenses' => $this->extract_av_expenses_data($income_statement, 'annual', $master_labels_annual), 'eps' => $this->extract_av_earnings_data($earnings, 'annual', $master_labels_annual), ];
        $quarterly = [ 'price' => $this->process_av_price_data($daily_data), 'revenue' => $this->extract_av_financial_data($income_statement, 'totalRevenue', 'quarterly', $master_labels_quarterly), 'net_income' => $this->extract_av_financial_data($income_statement, 'netIncome', 'quarterly', $master_labels_quarterly), 'ebitda' => $this->extract_av_financial_data($income_statement, 'ebitda', 'quarterly', $master_labels_quarterly), 'fcf' => $this->extract_av_fcf_data($cash_flow, 'quarterly', $master_labels_quarterly), 'cash_and_debt' => $this->extract_av_cash_and_debt_data($balance_sheet, 'quarterly', $master_labels_quarterly), 'dividend' => $this->aggregate_av_dividend_data($daily_data, 'quarterly', $master_labels_quarterly), 'shares_outstanding' => $this->extract_av_financial_data($balance_sheet, 'commonStockSharesOutstanding', 'quarterly', $master_labels_quarterly), 'expenses' => $this->extract_av_expenses_data($income_statement, 'quarterly', $master_labels_quarterly), 'eps' => $this->extract_av_earnings_data($earnings, 'quarterly', $master_labels_quarterly), ];
        return ['annual' => $annual, 'quarterly' => $quarterly];
    }
    
    private function get_master_labels($datasets, $type = 'annual', $limit_count = 10) {
        $all_dates = [];
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        $earnings_key = ($type === 'annual') ? 'annualEarnings' : 'quarterlyEarnings';
        foreach ($datasets as $dataset) {
            if (is_wp_error($dataset)) continue;
            if (isset($dataset[$report_key])) { foreach ($dataset[$report_key] as $report) { $all_dates[] = $report['fiscalDateEnding']; } } 
            elseif (isset($dataset[$earnings_key])) { foreach ($dataset[$earnings_key] as $report) { $all_dates[] = $report['fiscalDateEnding']; } }
        }
        $unique_dates = array_unique($all_dates); sort($unique_dates);
        $limit = ($type === 'annual') ? -$limit_count : -$limit_count;
        $limited_dates = array_slice($unique_dates, $limit); 
        $final_labels = [];
        if ($type === 'annual') {
            foreach ($limited_dates as $date) { $final_labels[] = substr($date, 0, 4); }
            return array_values(array_unique($final_labels));
        } else {
            return $limited_dates;
        }
    }

    private function extract_av_financial_data($reports, $key, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        if (is_wp_error($reports)) return $data;
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($reports[$report_key])) return $data;
        $data_map = [];
        foreach ($reports[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $data_map[$label] = isset($report[$key]) && is_numeric($report[$key]) ? (float)$report[$key] : 0;
        }
        foreach($master_labels as $i => $label) { if(isset($data_map[$label])) { $data['data'][$i] = $data_map[$label]; } }
        return $data;
    }

    private function extract_av_fcf_data($cash_flow_data, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        if (is_wp_error($cash_flow_data)) return $data;
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($cash_flow_data[$report_key])) return $data;
        $data_map = [];
        foreach ($cash_flow_data[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $operating_cash_flow = (float)($report['operatingCashflow'] ?? 0);
            $capex = (float)($report['capitalExpenditures'] ?? 0);
            $data_map[$label] = $operating_cash_flow - abs($capex);
        }
        foreach($master_labels as $i => $label) { if(isset($data_map[$label])) { $data['data'][$i] = $data_map[$label]; } }
        return $data;
    }

    private function extract_av_cash_and_debt_data($balance_sheet_data, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'datasets' => [ ['label' => 'Total Debt', 'data' => array_fill(0, count($master_labels), 0)], ['label' => 'Cash & Equivalents', 'data' => array_fill(0, count($master_labels), 0)] ]];
        if (is_wp_error($balance_sheet_data)) return $data;
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($balance_sheet_data[$report_key])) return $data;
        $debt_map = []; $cash_map = [];
        foreach ($balance_sheet_data[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $short_term_debt = (float)($report['shortTermDebt'] ?? 0); $long_term_debt = (float)($report['longTermDebt'] ?? 0);
            $debt_map[$label] = $short_term_debt + $long_term_debt;
            $cash_map[$label] = isset($report['cashAndCashEquivalentsAtCarryingValue']) && is_numeric($report['cashAndCashEquivalentsAtCarryingValue']) ? (float)$report['cashAndCashEquivalentsAtCarryingValue'] : 0;
        }
        foreach($master_labels as $i => $label) {
            if(isset($debt_map[$label])) $data['datasets'][0]['data'][$i] = $debt_map[$label];
            if(isset($cash_map[$label])) $data['datasets'][1]['data'][$i] = $cash_map[$label];
        }
        return $data;
    }

    private function extract_av_expenses_data($income_statement_data, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'datasets' => [ ['label' => 'SG&A', 'data' => array_fill(0, count($master_labels), 0)], ['label' => 'R&D', 'data' => array_fill(0, count($master_labels), 0)], ['label' => 'Interest Expense', 'data' => array_fill(0, count($master_labels), 0)] ]];
        if (is_wp_error($income_statement_data)) return $data;
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($income_statement_data[$report_key])) return $data;
        $sga_map = []; $rnd_map = []; $interest_map = [];
        foreach ($income_statement_data[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $sga_map[$label] = isset($report['sellingGeneralAndAdministrative']) && is_numeric($report['sellingGeneralAndAdministrative']) ? (float)$report['sellingGeneralAndAdministrative'] : 0;
            $rnd_map[$label] = isset($report['researchAndDevelopment']) && is_numeric($report['researchAndDevelopment']) ? (float)$report['researchAndDevelopment'] : 0;
            $interest_map[$label] = isset($report['interestExpense']) && is_numeric($report['interestExpense']) ? (float)$report['interestExpense'] : 0;
        }
        foreach($master_labels as $i => $label) {
            if(isset($sga_map[$label])) $data['datasets'][0]['data'][$i] = $sga_map[$label];
            if(isset($rnd_map[$label])) $data['datasets'][1]['data'][$i] = $rnd_map[$label];
            if(isset($interest_map[$label])) $data['datasets'][2]['data'][$i] = $interest_map[$label];
        }
        return $data;
    }

    private function aggregate_av_dividend_data($daily_data, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        if (is_wp_error($daily_data) || !isset($daily_data['Time Series (Daily)'])) return $data;
        $dividends_by_period = [];
        foreach ($daily_data['Time Series (Daily)'] as $date_str => $day_data) {
            $dividend_amount = (float)($day_data['7. dividend amount'] ?? 0);
            if ($dividend_amount > 0) {
                $dt = new DateTime($date_str); $period_key = '';
                if ($type === 'annual') { $period_key = $dt->format('Y'); } 
                else { $quarter = ceil((int)$dt->format('n') / 3); $year = $dt->format('Y'); $month = $quarter * 3; $day = date('t', mktime(0, 0, 0, $month, 1, $year)); $period_key = date('Y-m-d', strtotime("$year-$month-$day")); }
                if (!isset($dividends_by_period[$period_key])) $dividends_by_period[$period_key] = 0;
                $dividends_by_period[$period_key] += $dividend_amount;
            }
        }
        foreach($master_labels as $i => $label) { if(isset($dividends_by_period[$label])) { $data['data'][$i] = $dividends_by_period[$label]; } }
        return $data;
    }
    
    private function extract_av_earnings_data($earnings, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), 0)];
        if (is_wp_error($earnings)) return $data;
        $report_key = ($type === 'annual') ? 'annualEarnings' : 'quarterlyReports';
        if (!isset($earnings[$report_key])) return $data;
        $data_map = [];
        foreach ($earnings[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $data_map[$label] = isset($report['reportedEPS']) && is_numeric($report['reportedEPS']) ? (float)$report['reportedEPS'] : 0;
        }
        foreach($master_labels as $i => $label) { if(isset($data_map[$label])) { $data['data'][$i] = $data_map[$label]; } }
        return $data;
    }

    private function process_av_price_data($daily_data) {
        $data = ['labels' => [], 'data' => []];
        if (is_wp_error($daily_data) || !isset($daily_data['Time Series (Daily)'])) return $data;
        $time_series = array_slice($daily_data['Time Series (Daily)'], 0, 252 * 20, true);
        $time_series = array_reverse($time_series, true);
        foreach($time_series as $date => $day_data) { $data['labels'][] = $date; $data['data'][] = (float)$day_data['4. close']; }
        return $data;
    }
    
    private function format_large_number($number, $prefix = '$', $decimals = 1) {
        if (!is_numeric($number) || $number == 0) { return $prefix === '$' ? '$0' : '0'; }
        $abs_number = abs($number); $formatted_number = '';
        if ($abs_number >= 1.0e+12) { $formatted_number = round($number / 1.0e+12, $decimals) . 'T'; } 
        elseif ($abs_number >= 1.0e+9) { $formatted_number = round($number / 1.0e+9, $decimals) . 'B'; } 
        elseif ($abs_number >= 1.0e+6) { $formatted_number = round($number / 1.0e+6, $decimals) . 'M'; } 
        elseif ($abs_number >= 1.0e+3) { $formatted_number = round($number / 1.0e+3, $decimals) . 'K'; }
        else { $formatted_number = number_format($number, $decimals); }
        return $prefix . $formatted_number;
    }

    private function build_overview_section_html($overview, $quote) {
        $ticker = $overview['Symbol'] ?? 'N/A';
        $name = $overview['Name'] ?? '';
        $description = $overview['Description'] ?? 'No company description available.';
        $stock_price = !is_wp_error($quote) ? (float)($quote['05. price'] ?? 0) : 0;
        $week_high = (float)($overview['52WeekHigh'] ?? 0);
        $week_low = (float)($overview['52WeekLow'] ?? 0);
        $cik = $overview['CIK'] ?? null;
    
        $output = '<div class="jtw-content-section" id="section-overview-content">';
        $output .= '<h4>' . esc_html($ticker) . ' ' . esc_html__('Company Overview', 'journey-to-wealth') . '</h4>';
        
        // Overview Header Grid
        $output .= '<div class="jtw-overview-header-grid">';
        $output .= $this->create_metric_card('Current Price', $stock_price, '$');
        $output .= $this->create_metric_card('Market Capitalization', $overview['MarketCapitalization'] ?? 0, '$', '', true);
        $output .= $this->create_metric_card('Shares Outstanding', $overview['SharesOutstanding'] ?? 0, '', '', true);
        $output .= '</div>';
    
        $output .= '<div class="jtw-company-description"><p>' . esc_html($description) . '</p></div>';
    
        // Determine the display range for the progress bar
        $display_low = $week_low;
        $display_high = $week_high;
    
        if ($stock_price < $week_low) {
            $display_low = $stock_price;
        }
        if ($stock_price > $week_high) {
            $display_high = $stock_price;
        }
    
        // 52-Week Range Progress Bar
        $output .= '<div class="jtw-price-range-bar" data-low="' . esc_attr($display_low) . '" data-high="' . esc_attr($display_high) . '" data-current="' . esc_attr($stock_price) . '">';
        $output .= '<h5>52-Week Price Range</h5>';
        $output .= '<div class="jtw-progress-track">';
        $output .= '<div class="jtw-progress-fill" style="width: 0%;"></div>';
        $output .= '<div class="jtw-price-range-indicator" style="left: 0%;"></div>';
        $output .= '</div>';
        $output .= '<div class="jtw-price-range-labels">';
        $output .= '<span><strong>$' . esc_attr(number_format($display_low, 1)) . '</strong></span>';
        $output .= '<span><strong>Current: $' . esc_attr(number_format($stock_price, 1)) . '</strong></span>';
        $output .= '<span><strong>$' . esc_attr(number_format($display_high, 1)) . '</strong></span>';
        $output .= '</div>';
        $output .= '</div>';
    
        // Stats Grid
        $output .= '<div class="jtw-stats-grid">';
        
        $stats = [
            'PercentInsiders' => ['label' => 'Insider Ownership', 'prefix' => '', 'suffix' => '%', 'max' => 100],
            'PercentInstitutions' => ['label' => 'Institution Ownership', 'prefix' => '', 'suffix' => '%', 'max' => 100],
            '50DayMovingAverage' => ['label' => '50-Day Moving Average', 'prefix' => '$', 'max_key' => '52WeekHigh'],
            '200DayMovingAverage' => ['label' => '200-Day Moving Average', 'prefix' => '$', 'max_key' => '52WeekHigh'],
        ];
    
        foreach ($stats as $key => $details) {
            $value = (float)($overview[$key] ?? 0);
            $max_value = isset($details['max']) ? $details['max'] : (isset($details['max_key']) ? (float)($overview[$details['max_key']] ?? $value) : $value);
            if ($max_value == 0 && $value > 0) $max_value = $value * 1.25;
    
            $formatted_value = '';
            if (isset($details['format']) && $details['format'] === 'large') {
                $formatted_value = $this->format_large_number($value, $details['prefix']);
            } else {
                $formatted_value = $details['prefix'] . number_format($value, 1) . ($details['suffix'] ?? '');
            }
    
            $output .= '<div class="jtw-stat-item">';
            $output .= '<h4>' . esc_html($details['label']) . '</h4>';
            $output .= '<div class="jtw-progress-bar-container" data-value="' . esc_attr($value) . '" data-max="' . esc_attr($max_value) . '">';
            $output .= '<div class="jtw-progress-track"><div class="jtw-progress-fill" style="width: 0%;"></div></div>';
            $output .= '<span class="jtw-stat-value">' . esc_html($formatted_value) . '</span>';
            $output .= '</div>';
            $output .= '</div>';
        }
    
        $output .= '</div>'; // end .jtw-stats-grid
    
        // Company Details Button
        $output .= '<div class="jtw-details-button-container">';
        $output .= '<button class="jtw-modal-trigger jtw-details-button" data-modal-target="#jtw-company-details-modal">' . esc_html__('View Full Company Details', 'journey-to-wealth') . '</button>';
        $output .= '</div>';

        // SEC Filings Card
        if ($cik) {
            $sec_url = 'https://www.sec.gov/edgar/browse/?CIK=' . esc_attr($cik) . '&owner=exclude';
            $output .= '<a href="' . esc_url($sec_url) . '" target="_blank" rel="noopener noreferrer" class="jtw-sec-filings-card">';
            $output .= '<span>View All SEC Filings</span>';
            $output .= '</a>';
        }
    
        // Company Details Modal
        $modal_id = 'jtw-company-details-modal';
        $output .= '<div id="' . esc_attr($modal_id) . '" class="jtw-modal"><div class="jtw-modal-content"><span class="jtw-modal-close">&times;</span>';
        $output .= '<h4>' . esc_html__('Company Details', 'journey-to-wealth') . '</h4>';
        $output .= '<div class="jtw-details-grid">';
        
        $details_map = [
            'Exchange' => 'Exchange',
            'Sector' => 'Sector',
            'Industry' => 'Industry',
            'FiscalYearEnd' => 'Fiscal Year End',
            'LatestQuarter' => 'Latest Quarter',
            'ExDividendDate' => 'Ex-Dividend Date',
            'DividendDate' => 'Dividend Date'
        ];
    
        foreach ($details_map as $key => $title) {
            $value = $overview[$key] ?? 'N/A';
            if (($key === 'LatestQuarter' || $key === 'ExDividendDate' || $key === 'DividendDate') && $value !== 'N/A' && $value !== 'None') {
                $value = date('F j, Y', strtotime($value));
            }
            if ($value !== 'N/A' && $value !== 'None') {
                 $output .= $this->create_metric_card($title, $value);
            }
        }
    
        // Website card
        $website = $overview['OfficialSite'] ?? ($overview['Website'] ?? 'N/A');
        if ($website !== 'N/A' && $website !== 'None' && filter_var($website, FILTER_VALIDATE_URL)) {
            $hostname = parse_url($website, PHP_URL_HOST) ?: $website;
            $output .= '<div class="jtw-metric-card"><h3 class="jtw-metric-title">Website</h3><p class="jtw-metric-value"><a href="' . esc_url($website) . '" target="_blank" rel="noopener noreferrer">' . esc_html($hostname) . '</a></p></div>';
        }
    
        // Address card
        $address = $overview['Address'] ?? 'N/A';
        if ($address !== 'N/A' && $address !== 'None') {
            $output .= $this->create_metric_card('Address', $address, '', 'jtw-address-card');
        }
    
        $output .= '</div>'; // end .jtw-details-grid
        $output .= '</div></div>'; // end modal content and modal
    
        $output .= '<div class="jtw-modal-overlay"></div>';
        $output .= '</div>'; // end .jtw-content-section
        return $output;
    }
    
    private function build_key_metrics_ratios_section_html($ticker, $primary_metrics) {
        $output = '<div id="section-key-metrics-ratios-content" class="jtw-content-section">';
        
        // Section Header with Toggle
        $output .= '<div class="jtw-section-header">';
        $output .= '<h4>' . esc_html__('Key Metrics & Ratios', 'journey-to-wealth') . '</h4>';
        $output .= '<div class="jtw-peer-toggle-container">';
        $output .= '<span>' . esc_html__('Peer Comparison', 'journey-to-wealth') . '</span>';
        $output .= '<label class="jtw-switch"><input type="checkbox" id="jtw-peer-toggle"><span class="jtw-slider round"></span></label>';
        $output .= '</div></div>';
        
        $output .= '<div class="jtw-metrics-table-container">';
        $output .= '<table class="jtw-metrics-table">';
        
        // Table Header
        $output .= '<thead><tr>';
        $output .= '<th>Metric</th>';
        $output .= '<th class="jtw-primary-col">' . esc_html($ticker) . '</th>';
        $output .= '<th class="jtw-peer-col jtw-peer-1-header" style="display:none;"></th>';
        $output .= '<th class="jtw-peer-col jtw-peer-2-header" style="display:none;"></th>';
        $output .= '</tr></thead>';
    
        // Table Body
        $output .= '<tbody>';
    
        $metric_groups = [
            'Valuation' => [
                'trailingPeRatio' => ['label' => 'TTM P/E Ratio', 'suffix' => 'x'],
                'forwardPeRatio' => ['label' => 'Forward P/E Ratio', 'suffix' => 'x'],
            ],
            'EPS Growth' => [
                'ttmEpsGrowth' => ['label' => 'TTM EPS Growth', 'suffix' => '%'],
                'currentYearEpsGrowth' => ['label' => 'Current Year EPS Growth (Est)', 'suffix' => '%'],
                'nextYearEpsGrowth' => ['label' => 'Next Year EPS Growth (Est)', 'suffix' => '%'],
            ],
            'Revenue Growth' => [
                'ttmRevenueGrowth' => ['label' => 'TTM Revenue Growth', 'suffix' => '%'],
                'currentYearRevenueGrowth' => ['label' => 'Current Year Revenue Growth (Est)', 'suffix' => '%'],
                'nextYearRevenueGrowth' => ['label' => 'Next Year Revenue Growth (Est)', 'suffix' => '%'],
            ],
            'Profitability' => [
                'grossMargin' => ['label' => 'Gross Margin', 'suffix' => '%'],
                'netMargin' => ['label' => 'Net Profit Margin', 'suffix' => '%'],
            ],
            'Other Ratios' => [
                'psRatio' => ['label' => 'TTM P/S Ratio', 'suffix' => 'x'],
                'pbRatio' => ['label' => 'TTM P/B Ratio', 'suffix' => 'x'],
                'evToRevenue' => ['label' => 'EV/Revenue', 'suffix' => 'x'],
                'evToEbitda' => ['label' => 'EV/EBITDA', 'suffix' => 'x'],
                'pegRatio' => ['label' => 'PEG / PEGY Ratios', 'suffix' => 'x', 'is_peg' => true],
            ]
        ];
    
        foreach ($metric_groups as $group_name => $metrics) {
            $output .= '<tr class="jtw-metric-group-header"><td colspan="4">' . esc_html($group_name) . '</td></tr>';
            foreach ($metrics as $key => $details) {
                $output .= '<tr>';
                
                // Handle the special PEG/PEGY row with the calculator link
                if (isset($details['is_peg']) && $details['is_peg']) {
                    $peg_val = $this->format_metric_value($primary_metrics['pegRatio'] ?? 'N/A', 'x');
                    $pegy_val = $this->format_metric_value($primary_metrics['pegyRatio'] ?? 'N/A', 'x');
                    $output .= '<td>' . esc_html($details['label']) . ' <a href="#" class="jtw-modal-trigger" data-modal-target="#jtw-peg-pegy-modal">(Calculator)</a></td>';
                    $output .= '<td class="jtw-primary-col">' . esc_html($peg_val . ' / ' . $pegy_val) . '</td>';
                    $output .= '<td class="jtw-peer-col jtw-peer-1-value" data-metric-peg="pegRatio" data-metric-pegy="pegyRatio" style="display:none;">-</td>';
                    $output .= '<td class="jtw-peer-col jtw-peer-2-value" data-metric-peg="pegRatio" data-metric-pegy="pegyRatio" style="display:none;">-</td>';
                } else {
                    $output .= '<td>' . esc_html($details['label']) . '</td>';
                    $output .= '<td class="jtw-primary-col">' . $this->format_metric_value($primary_metrics[$key] ?? 'N/A', $details['suffix']) . '</td>';
                    $output .= '<td class="jtw-peer-col jtw-peer-1-value" data-metric="' . esc_attr($key) . '" style="display:none;">-</td>';
                    $output .= '<td class="jtw-peer-col jtw-peer-2-value" data-metric="' . esc_attr($key) . '" style="display:none;">-</td>';
                }
                $output .= '</tr>';
            }
        }
    
        $output .= '</tbody></table>';
        $output .= '<div class="jtw-peer-loading-spinner" style="display:none;"><div class="jtw-loading-spinner"></div></div>';
        $output .= '<div class="jtw-peer-error-message" style="display:none;"></div>';
        $output .= '</div>'; // end table container
    
        // PEG/PEGY Calculator Modal
        $output .= '<div id="jtw-peg-pegy-modal" class="jtw-modal"><div class="jtw-modal-content"><span class="jtw-modal-close">&times;</span><h4>PEG/PEGY Calculator</h4>';
        if (!is_numeric($primary_metrics['trailingEps']) || $primary_metrics['trailingEps'] <= 0) {
            $output .= '<div class="jtw-metric-card"><p><strong>' . esc_html__('The company is not profitable yet.', 'journey-to-wealth') . '</strong></p></div>';
        } else {
            $growth_default = number_format((float)($primary_metrics['defaultGrowth'] ?? 5), 1, '.', '');
            $dividend_yield_default = number_format((float)($primary_metrics['dividendYield'] ?? 0), 1, '.', '');
            $output .= '<div class="jtw-peg-pegy-calculator"><div class="jtw-peg-pegy-inputs-grid">';
            $output .= '<div class="jtw-form-group"><label for="jtw-sim-stock-price">Stock Price ($):</label><input type="number" step="0.01" id="jtw-sim-stock-price" class="jtw-sim-input" value="' . esc_attr($primary_metrics['stockPrice']) . '"></div>';
            $output .= '<div class="jtw-form-group"><label for="jtw-sim-eps">Earnings per Share ($):</label><input type="number" step="0.01" id="jtw-sim-eps" class="jtw-sim-input" value="' . esc_attr($primary_metrics['trailingEps']) . '"></div>';
            $output .= '<div class="jtw-form-group"><label for="jtw-sim-growth-rate">Est. Annual Earnings Growth (%):</label><input type="number" step="0.1" id="jtw-sim-growth-rate" class="jtw-sim-input" value="' . esc_attr($growth_default) . '"></div>';
            $output .= '<div class="jtw-form-group"><label for="jtw-sim-dividend-yield">Est. Annual Dividend Yield (%):</label><input type="number" step="0.01" id="jtw-sim-dividend-yield" class="jtw-sim-input" value="' . esc_attr($dividend_yield_default) . '"></div>';
            $output .= '</div><div class="jtw-peg-pegy-results">';
            $output .= '<div class="jtw-bar-result"><span class="jtw-result-label">PEG Ratio</span><div class="jtw-bar-container"><div id="jtw-peg-bar" class="jtw-bar"><span id="jtw-peg-value" class="jtw-bar-value">-</span></div></div></div>';
            $output .= '<div class="jtw-bar-result"><span class="jtw-result-label">PEGY Ratio</span><div class="jtw-bar-container"><div id="jtw-pegy-bar" class="jtw-bar"><span id="jtw-pegy-value" class="jtw-bar-value">-</span></div></div></div>';
            $output .= '</div></div>';
        }
        $output .= '</div></div>'; // End modal
    
        $output .= '</div>'; // End section
        return $output;
    }

    private function format_metric_value($value, $suffix = '') {
        if (is_numeric($value)) {
            return number_format($value, 1) . $suffix;
        }
        return 'N/A';
    }

    private function build_metric_list_item($label, $value, $suffix = '') {
        $formatted_value = 'N/A';
        if (is_numeric($value)) {
            $formatted_value = number_format($value, 1) . $suffix;
        }
        return '<div class="jtw-metric-list-item"><span class="jtw-metric-label">' . esc_html($label) . '</span><span class="jtw-metric-value">' . esc_html($formatted_value) . '</span></div>';
    }

    private function build_past_performance_section_html($historical_data) {
        $unique_id = 'hist-trends-' . uniqid();
        $output = '<div id="section-past-performance-content" class="jtw-content-section">';
        $output .= '<h4>' . esc_html__('Visual Trends', 'journey-to-wealth') . '</h4>';
        $output .= '<div class="jtw-chart-controls"><div class="jtw-period-toggle"><button class="jtw-period-button active" data-period="annual">Annual</button><button class="jtw-period-button" data-period="quarterly">Quarterly</button></div>';
        $output .= '<div class="jtw-chart-filter-toggle"><button class="jtw-category-button active" data-category="all">All Charts</button><button class="jtw-category-button" data-category="growth">Growth</button><button class="jtw-category-button" data-category="profitability">Profitability</button><button class="jtw-category-button" data-category="financial_health">Financial Health</button><button class="jtw-category-button" data-category="dividends_capital">Dividends & Capital</button></div></div>';
        $output .= '<div class="jtw-historical-charts-grid" id="' . esc_attr($unique_id) . '">';
        $chart_configs = [ 'revenue' => ['title' => 'Revenue', 'type' => 'bar', 'prefix' => '$', 'category' => 'growth', 'colors' => ['#ffc107']], 'net_income' => ['title' => 'Net Income', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#fd7e14']], 'ebitda' => ['title' => 'EBITDA', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#82ca9d']], 'fcf' => ['title' => 'Free Cash Flow', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#20c997']], 'cash_and_debt' => ['title' => 'Cash & Debt', 'type' => 'bar', 'prefix' => '$', 'category' => 'financial_health', 'colors' => ['#dc3545', '#28a745']], 'expenses' => ['title' => 'Expenses', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#007bff', '#fd7e14', '#6c757d']], 'dividend' => ['title' => 'Dividend Per Share', 'type' => 'bar', 'prefix' => '$', 'category' => 'dividends_capital', 'colors' => ['#6f42c1']], 'shares_outstanding' => ['title' => 'Shares Outstanding', 'type' => 'bar', 'prefix' => '', 'category' => 'dividends_capital', 'colors' => ['#17a2b8']], 'eps' => ['title' => 'EPS', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#ffc107']], ];
        foreach($chart_configs as $key => $config) {
            $annual_data = $historical_data['annual'][$key] ?? []; $quarterly_data = $historical_data['quarterly'][$key] ?? [];
            $has_data = !empty($annual_data) && (isset($annual_data['data']) || (isset($annual_data['datasets']) && !empty($annual_data['datasets'][0]['data'])));
            if (!$has_data) continue;
            $chart_id = 'chart-' . strtolower(str_replace(' ', '-', $key)) . '-' . uniqid();
            $output .= '<div class="jtw-chart-item" data-category="' . esc_attr($config['category']) . '">';
            $output .= '<h5>' . esc_html($config['title']) . '</h5><div class="jtw-chart-wrapper"><canvas id="' . esc_attr($chart_id) . '"></canvas></div>';
            $output .= "<script type='application/json' class='jtw-chart-data' data-chart-id='" . esc_attr($chart_id) . "' data-chart-type='" . esc_attr($config['type']) . "' data-prefix='" . esc_attr($config['prefix']) . "' data-annual='" . esc_attr(json_encode($annual_data)) . "' data-quarterly='" . esc_attr(json_encode($quarterly_data)) . "' data-colors='" . esc_attr(json_encode($config['colors'])) . "'></script>";
            $output .= '</div>';
        }
        $output .= '</div></div>';
        return $output;
    }

    private function build_intrinsic_valuation_section_html($valuation_data, $valuation_summary, $details, $income_statement) {
        $output = '<div id="section-intrinsic-valuation-content" class="jtw-content-section">';
        $output .= '<div class="jtw-section-header"><h4>' . esc_html__('Fair Value Analysis', 'journey-to-wealth') . '</h4>';
        $has_valid_models = false;
        foreach ($valuation_data as $result) { if (!is_wp_error($result)) { $has_valid_models = true; break; } }
        if ($has_valid_models) { $output .= '<button class="jtw-modal-trigger" data-modal-target="#jtw-assumptions-modal">' . esc_html__('View Assumptions', 'journey-to-wealth') . '</button>'; }
        $output .= '</div>';
    
        // --- Assumptions Table ---
        $dcf_result = $valuation_data['DCF Model'] ?? null;
        if ($dcf_result && !is_wp_error($dcf_result)) {
            $analyst_growth = ($dcf_result['calculation_breakdown']['inputs']['initial_growth_rate'] ?? 0) * 100;
            $analyst_fcfe = $dcf_result['calculation_breakdown']['inputs']['base_cash_flow'] ?? 0;
            
            $fcfe_label = 'Initial FCFE';
            $fcfe_display_value = $analyst_fcfe;
            $fcfe_multiplier = 1;
    
            if (abs($analyst_fcfe) >= 1.0e+9) {
                $fcfe_label .= ' (in Billions)';
                $fcfe_display_value = round($analyst_fcfe / 1.0e+9, 2);
                $fcfe_multiplier = 1.0e+9;
            } elseif (abs($analyst_fcfe) >= 1.0e+6) {
                $fcfe_label .= ' (in Millions)';
                $fcfe_display_value = round($analyst_fcfe / 1.0e+6, 2);
                $fcfe_multiplier = 1.0e+6;
            } elseif (abs($analyst_fcfe) >= 1.0e+3) {
                $fcfe_label .= ' (in Thousands)';
                $fcfe_display_value = round($analyst_fcfe / 1.0e+3, 2);
                $fcfe_multiplier = 1.0e+3;
            }
            
            $bear_growth = $analyst_growth - 5;
            $base_growth = $analyst_growth;
            $bull_growth = $analyst_growth + 5;

            $fcfe_raw_value_attr = 'data-raw-value="' . esc_attr($analyst_fcfe) . '"';

            $output .= '<table class="jtw-assumptions-table">';
            $output .= '<thead><tr><th></th><th>Analyst Assumptions</th><th>Bear Case</th><th>Base Case</th><th>Bull Case</th></tr></thead>';
            $output .= '<tbody>';
            $output .= '<tr><td data-label="Metric">Revenue Growth %</td><td data-label="Analyst">' . esc_html(number_format($analyst_growth, 1)) . '%</td><td data-label="Bear"><input type="number" step="0.1" class="jtw-assumption-input" data-case="bear" data-metric="revGrowth" value="' . esc_attr(number_format($bear_growth, 1)) . '"></td><td data-label="Base"><input type="number" step="0.1" class="jtw-assumption-input" data-case="base" data-metric="revGrowth" value="' . esc_attr(number_format($base_growth, 1)) . '"></td><td data-label="Bull"><input type="number" step="0.1" class="jtw-assumption-input" data-case="bull" data-metric="revGrowth" value="' . esc_attr(number_format($bull_growth, 1)) . '"></td></tr>';
            $output .= '<tr><td data-label="Metric">' . esc_html($fcfe_label) . '</td><td data-label="Analyst">' . esc_html($this->format_large_number($analyst_fcfe)) . '</td><td data-label="Bear"><input type="number" step="0.01" class="jtw-assumption-input" data-case="bear" data-metric="initialFcfe" value="' . esc_attr($fcfe_display_value) . '" data-multiplier="' . esc_attr($fcfe_multiplier) . '" ' . $fcfe_raw_value_attr . '></td><td data-label="Base"><input type="number" step="0.01" class="jtw-assumption-input" data-case="base" data-metric="initialFcfe" value="' . esc_attr($fcfe_display_value) . '" data-multiplier="' . esc_attr($fcfe_multiplier) . '" ' . $fcfe_raw_value_attr . '></td><td data-label="Bull"><input type="number" step="0.01" class="jtw-assumption-input" data-case="bull" data-metric="initialFcfe" value="' . esc_attr($fcfe_display_value) . '" data-multiplier="' . esc_attr($fcfe_multiplier) . '" ' . $fcfe_raw_value_attr . '></td></tr>';
            $output .= '<tr><td data-label="Metric">Desired Annual Return %</td><td data-label="Analyst">-</td><td data-label="Bear"><input type="number" step="0.1" class="jtw-assumption-input" data-case="bear" data-metric="desiredReturn" value="15"></td><td data-label="Base"><input type="number" step="0.1" class="jtw-assumption-input" data-case="base" data-metric="desiredReturn" value="15"></td><td data-label="Bull"><input type="number" step="0.1" class="jtw-assumption-input" data-case="bull" data-metric="desiredReturn" value="15"></td></tr>';
            $output .= '<tr class="jtw-results-row"><td class="jtw-results-label">Fair Value</td><td class="jtw-analyst-fv">$' . number_format($valuation_summary['fair_value'], 2) . '</td><td class="jtw-bear-fv">-</td><td class="jtw-base-fv">-</td><td class="jtw-bull-fv">-</td></tr>';
            $output .= '<tr class="jtw-results-row"><td class="jtw-results-label">Buy Price</td><td class="jtw-analyst-buy">-</td><td class="jtw-bear-buy">-</td><td class="jtw-base-buy">-</td><td class="jtw-bull-buy">-</td></tr>';
            $output .= '</tbody>';
            $output .= '</table>';
        }
    
        $output .= '<div id="jtw-interactive-chart-container" class="jtw-valuation-chart-container" data-current-price="' . esc_attr($valuation_summary['current_price']) . '" data-analyst-fv="' . esc_attr($valuation_summary['fair_value']) . '"><canvas id="jtw-interactive-valuation-chart"></canvas></div>';
    
        if ($has_valid_models) {
            $modal_id = 'jtw-assumptions-modal';
            $output .= '<div id="' . $modal_id . '" class="jtw-modal"><div class="jtw-modal-content"><span class="jtw-modal-close">&times;</span>';
            foreach ($valuation_data as $model_name => $result) {
                if (is_wp_error($result)) { $output .= '<h4>' . esc_html($model_name) . '</h4><div class="jtw-metric-card"><p><strong>' . esc_html__('Error:', 'journey-to-wealth') . '</strong> ' . esc_html($result->get_error_message()) . '</p></div>';
                } else { $output .= $this->build_valuation_assumptions_modal_html($result, $details); }
            }
            $output .= '</div></div>';
        }
        $output .= '<div class="jtw-modal-overlay"></div></div>';
        return $output;
    }

    private function build_valuation_assumptions_modal_html($result, $details) {
        $data = $result['calculation_breakdown'];
        $ticker = $details['Symbol'] ?? 'the company';
        $model_name = $data['model_name'] ?? 'Valuation';
        $output = '<h4>' . esc_html($model_name) . ' Assumptions for ' . esc_html($ticker) . '</h4>';
        switch($model_name) {
            case 'DCF Model (FCFE)': $output .= $this->build_dcf_modal_content($result, $details); break;
            case 'Dividend Discount Model': $output .= $this->build_ddm_modal_content($result, $details); break;
            case 'AFFO Model': $output .= $this->build_affo_modal_content($result, $details); break;
            case 'Excess Return Model': $output .= $this->build_excess_return_modal_content($result, $details); break;
            default: $output .= '<pre>' . print_r($data, true) . '</pre>';
        }
        return $output;
    }

    private function build_dcf_modal_content($result, $details) {
        $data = $result['calculation_breakdown'];
        $value_per_share = $result['intrinsic_value_per_share'];
        $discount_calc = $data['discount_rate_calc'];
        $beta_details = $discount_calc['beta_details'];
        $current_price = $data['current_price'];
        $discount_pct = ($value_per_share > 0 && $current_price > 0) ? (($value_per_share - $current_price) / $current_price) * 100 : 0;
        $total_value_label = 'Total Equity Value';
        $output = '<h4>Valuation</h4><table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Source</th><th>Value</th></tr></thead><tbody>';
        $output .= '<tr><td>Valuation Model</td><td></td><td>2 Stage FCFE</td></tr>';
        $output .= '<tr><td>Base Cash Flow</td><td>' . esc_html($data['inputs']['base_cash_flow_source']) . '</td><td>' . $this->format_large_number($data['inputs']['base_cash_flow'], '$') . '</td></tr>';
        $output .= '<tr><td>Initial Growth Rate</td><td>' . esc_html($data['inputs']['growth_rate_source']) . '</td><td>' . number_format($data['inputs']['initial_growth_rate'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td>Discount Rate (Cost of Equity)</td><td>See below</td><td>' . number_format($data['inputs']['discount_rate'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td>Perpetual Growth Rate</td><td>' . esc_html($discount_calc['risk_free_rate_source']) . '</td><td>' . number_format($data['inputs']['terminal_growth_rate'] * 100, 1) . '%</td></tr>';
        $output .= '</tbody></table>';
        $output .= '<h4>Discount Rate</h4><table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Calculation/ Source</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Risk-Free Rate</td><td>' . esc_html($discount_calc['risk_free_rate_source']) . '</td><td>' . number_format($discount_calc['risk_free_rate'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td>Equity Risk Premium</td><td>' . esc_html($discount_calc['erp_source']) . '</td><td>' . number_format($discount_calc['equity_risk_premium'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td><strong>Discount Rate/ Cost of Equity</strong></td><td><strong>' . esc_html($discount_calc['cost_of_equity_calc']) . '</strong></td><td><strong>' . number_format($data['inputs']['discount_rate'] * 100, 1) . '%</strong></td></tr>';
        $output .= '</tbody></table>';
        if (isset($beta_details['unlevered_beta_avg'])) {
            $output .= '<h4>Levered Beta Calculation</h4><table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Calculation/ Source</th><th>Result</th></tr></thead><tbody>';
            $output .= '<tr><td>Unlevered Beta</td><td>Damodaran Industry Average</td><td>' . number_format($beta_details['unlevered_beta_avg'], 3) . '</td></tr>';
            if (isset($beta_details['relevered_beta_calc'])) { $output .= '<tr><td>Re-levered Beta</td><td>' . esc_html($beta_details['relevered_beta_calc']) . '</td><td>' . number_format($beta_details['unconstrained_levered_beta'], 3) . '</td></tr>'; }
            $output .= '<tr><td>Levered Beta</td><td>' . esc_html($beta_details['beta_source']) . '</td><td>' . number_format($discount_calc['beta'], 3) . '</td></tr>';
            $output .= '</tbody></table>';
        }
        $output .= '<h4>FCFE Forecast</h4><table class="jtw-modal-table"><thead><tr><th></th><th>FCFE (USD)</th><th>Growth Rate</th><th>Present Value Discounted (@' . number_format($data['inputs']['discount_rate'] * 100, 1) . '%)</th></tr></thead><tbody>';
        foreach ($data['projection_table'] as $row) {
            $output .= '<tr><td>' . esc_html($row['year']) . '</td><td>' . $this->format_large_number($row['cf'], '$') . '</td><td>' . number_format($row['growth_rate'] * 100, 1) . '%</td><td>' . $this->format_large_number($row['pv_cf'], '$') . '</td></tr>';
        }
        $output .= '<tr><td colspan="3"><strong>Present value of next 10 years cash flows</strong></td><td><strong>' . $this->format_large_number($data['sum_of_pv_cfs'], '$') . '</strong></td></tr>';
        $output .= '</tbody></table>';
        $output .= '<h4>Final Valuation</h4><table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Terminal Value</td><td>FCFE<sub>' . end($data['projection_table'])['year'] . '</sub> &times; (1 + g) &divide; (Discount Rate - g)<br>= ' . $this->format_large_number(end($data['projection_table'])['cf'], '$') . ' &times; (1 + ' . number_format($data['inputs']['terminal_growth_rate'] * 100, 1) . '%) &divide; (' . number_format($data['inputs']['discount_rate'] * 100, 1) . '% - ' . number_format($data['inputs']['terminal_growth_rate'] * 100, 1) . '%)</td><td>' . $this->format_large_number($data['terminal_value'], '$') . '</td></tr>';
        $output .= '<tr><td>Present Value of Terminal Value</td><td>Terminal Value &divide; (1 + r)<sup>10</sup><br>' . $this->format_large_number($data['terminal_value'], '$') . ' &divide; (1 + ' . number_format($data['inputs']['discount_rate'] * 100, 1) . '%)<sup>10</sup></td><td>' . $this->format_large_number($data['pv_of_terminal_value'], '$') . '</td></tr>';
        $output .= '<tr><td><strong>' . esc_html($total_value_label) . '</strong></td><td><strong>Present value of next 10 years cash flows + PV of Terminal Value</strong><br>= ' . $this->format_large_number($data['sum_of_pv_cfs'], '$') . ' + ' . $this->format_large_number($data['pv_of_terminal_value'], '$') . '</td><td><strong>' . $this->format_large_number($data['total_equity_value'], '$') . '</strong></td></tr>';
        $output .= '<tr><td><strong>Equity Value per Share (USD)</strong></td><td><strong>Total Equity Value / Shares Outstanding</strong><br>= ' . $this->format_large_number($data['total_equity_value'], '$') . ' / ' . number_format($data['shares_outstanding']) . '</td><td><strong>$' . number_format($value_per_share, 1) . '</strong></td></tr>';
        $output .= '</tbody></table>';
        $output .= '<h4>Discount to Share Price</h4><table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Value per share (USD)</td><td>From above.</td><td>$' . number_format($value_per_share, 1) . '</td></tr>';
        $output .= '<tr><td>Current discount</td><td>Discount to share price of $' . number_format($current_price, 1) . '<br>= ($' . number_format($value_per_share, 1) . ' - $' . number_format($current_price, 1) . ') / $' . number_format($current_price, 1) . '</td><td>' . number_format($discount_pct, 1) . '%</td></tr>';
        $output .= '</tbody></table>';
        return $output;
    }

    private function build_ddm_modal_content($result, $details) {
        $data = $result['calculation_breakdown'];
        $value_per_share = $result['intrinsic_value_per_share'];
        $current_price = $data['current_price'];
        $discount_pct = ($value_per_share > 0) ? (($value_per_share - $current_price) / $current_price) * 100 : 0;

        $output = '<h4>Key Inputs & Calculation</h4><table class="jtw-modal-table"><tbody>';
        $output .= '<tr><td>Annual Dividend (D0)</td><td>$' . number_format($data['d0'], 1) . '</td></tr>';
        $output .= '<tr><td>Discount Rate (Cost of Equity)</td><td>' . number_format($data['cost_of_equity'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td>Perpetual Growth Rate (g)</td><td>' . number_format($data['growth_rate'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td><strong>Calculation</strong></td><td>(D0 * (1 + g)) / (Cost of Equity - g)</td></tr>';
        $output .= '<tr><td><strong>Intrinsic Value</strong></td><td><strong>$' . number_format($value_per_share, 1) . '</strong></td></tr>';
        $output .= '</tbody></table>';

        $output .= '<h4>Discount to Share Price</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Value per share (USD)</td><td>From above.</td><td>$' . number_format($value_per_share, 1) . '</td></tr>';
        $output .= '<tr><td>Current discount</td><td>Discount to share price of $' . number_format($current_price, 1) . '<br>= ($' . number_format($value_per_share, 1) . ' - $' . number_format($current_price, 1) . ') / $' . number_format($current_price, 1) . '</td><td>' . number_format($discount_pct, 1) . '%</td></tr>';
        $output .= '</tbody></table>';
        return $output;
    }

    private function build_affo_modal_content($result, $details) {
        $data = $result['calculation_breakdown'];
        $value_per_share = $result['intrinsic_value_per_share'];
        $discount_calc = $data['discount_rate_calc'];
        $beta_details = $discount_calc['beta_details'];
        $current_price = $data['current_price'];
        $discount_pct = ($value_per_share > 0) ? (($value_per_share - $current_price) / $value_per_share) * 100 : 0;
    
        // Table 1: Main Valuation Inputs
        $output = '<h4>Valuation</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Source</th><th>Value</th></tr></thead><tbody>';
        $output .= '<tr><td>Valuation Model</td><td></td><td>2 Stage FCF to Equity using AFFO</td></tr>';
        $output .= '<tr><td>Levered Adjusted Funds From Operations</td><td>Historical Growth</td><td>See below</td></tr>';
        $output .= '<tr><td>Discount Rate (Cost of Equity)</td><td>See below</td><td>' . number_format($data['inputs']['cost_of_equity'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td>Perpetual Growth Rate</td><td>' . esc_html($discount_calc['risk_free_rate_source']) . '</td><td>' . number_format($data['inputs']['terminal_growth_rate'] * 100, 1) . '%</td></tr>';
        $output .= '</tbody></table>';

        // Table 2: Discount Rate Calculation
        $output .= '<h4>Discount Rate</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Calculation/ Source</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Risk-Free Rate</td><td>' . esc_html($discount_calc['risk_free_rate_source']) . '</td><td>' . number_format($discount_calc['risk_free_rate'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td>Equity Risk Premium</td><td>' . esc_html($discount_calc['erp_source']) . '</td><td>' . number_format($discount_calc['equity_risk_premium'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td><strong>Discount Rate/ Cost of Equity</strong></td><td><strong>' . esc_html($discount_calc['cost_of_equity_calc']) . '</strong></td><td><strong>' . number_format($data['inputs']['cost_of_equity'] * 100, 1) . '%</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table for Levered Beta Calculation
        if (isset($beta_details['unlevered_beta_avg'])) {
            $output .= '<h4>Levered Beta Calculation</h4>';
            $output .= '<table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Calculation/ Source</th><th>Result</th></tr></thead><tbody>';
            $output .= '<tr><td>Unlevered Beta</td><td>Damodaran Industry Average</td><td>' . number_format($beta_details['unlevered_beta_avg'], 3) . '</td></tr>';
            if (isset($beta_details['relevered_beta_calc'])) {
                 $output .= '<tr><td>Re-levered Beta</td><td>' . esc_html($beta_details['relevered_beta_calc']) . '</td><td>' . number_format($beta_details['unconstrained_levered_beta'], 3) . '</td></tr>';
            }
            $output .= '<tr><td>Levered Beta</td><td>Levered Beta limited to 0.8 to 2.0<br>(practical range for a stable firm)</td><td>' . number_format($discount_calc['beta'], 3) . '</td></tr>';
        $output .= '</tbody></table>';
        }
        
        // Table 3: AFFO Projections
        $output .= '<h4>AFFO Forecast</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>AFFO (USD)</th><th>Source</th><th>Present Value Discounted (@' . number_format($data['inputs']['cost_of_equity'] * 100, 1) . '%)</th></tr></thead><tbody>';
        foreach ($data['projection_table'] as $row) {
            $output .= '<tr>';
            $output .= '<td>' . esc_html($row['year']) . '</td>';
            $output .= '<td>' . $this->format_large_number($row['affo'], '$') . '</td>';
            $output .= '<td>Est @ ' . number_format(($row['affo'] / ($data['projection_table'][0]['affo'] / (1 + $data['inputs']['initial_growth_rate'])) - 1) * 100, 1) . '%</td>';
            $output .= '<td>' . $this->format_large_number($row['pv_affo'], '$') . '</td>';
            $output .= '</tr>';
        }
        $output .= '<tr><td colspan="3"><strong>Present value of next 10 years cash flows</strong></td><td><strong>' . $this->format_large_number($data['sum_of_pv_affos'], '$') . '</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table 4 & 5: Final Valuation
        $output .= '<h4>Final Valuation</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Terminal Value</td><td>FCF<sub>2035</sub> &times; (1 + g) &divide; (Discount Rate - g)<br>= ' . $this->format_large_number(end($data['projection_table'])['affo'], '$') . ' &times; (1 + ' . number_format($data['inputs']['terminal_growth_rate'] * 100, 1) . '%) &divide; (' . number_format($data['inputs']['cost_of_equity'] * 100, 1) . '% - ' . number_format($data['inputs']['terminal_growth_rate'] * 100, 1) . '%)</td><td>' . $this->format_large_number($data['terminal_value'], '$') . '</td></tr>';
        $output .= '<tr><td>Present Value of Terminal Value</td><td>Terminal Value &divide; (1 + r)<sup>10</sup><br>' . $this->format_large_number($data['terminal_value'], '$') . ' &divide; (1 + ' . number_format($data['inputs']['cost_of_equity'] * 100, 1) . '%)<sup>10</sup></td><td>' . $this->format_large_number($data['pv_of_terminal_value'], '$') . '</td></tr>';
        $output .= '<tr><td><strong>Total Equity Value</strong></td><td><strong>Present value of next 10 years cash flows + PV of Terminal Value</strong><br>= ' . $this->format_large_number($data['sum_of_pv_affos'], '$') . ' + ' . $this->format_large_number($data['pv_of_terminal_value'], '$') . '</td><td><strong>' . $this->format_large_number($data['total_equity_value'], '$') . '</strong></td></tr>';
        $output .= '<tr><td><strong>Equity Value per Share (USD)</strong></td><td><strong>Total Equity Value / Shares Outstanding</strong><br>= ' . $this->format_large_number($data['total_equity_value'], '$') . ' / ' . number_format($data['shares_outstanding']) . '</td><td><strong>$' . number_format($value_per_share, 1) . '</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table 6: Discount
        $output .= '<h4>Discount to Share Price</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Value per share (USD)</td><td>From above.</td><td>$' . number_format($value_per_share, 1) . '</td></tr>';
        $output .= '<tr><td>Current discount</td><td>Discount to share price of $' . number_format($current_price, 1) . '<br>= ($' . number_format($value_per_share, 1) . ' - $' . number_format($current_price, 1) . ') / $' . number_format($current_price, 1) . '</td><td>' . number_format($discount_pct, 1) . '%</td></tr>';
        $output .= '</tbody></table>';

        return $output;
    }

    private function build_excess_return_modal_content($result, $details) {
        $data = $result['calculation_breakdown'];
        $value_per_share = $result['intrinsic_value_per_share'];
        $discount_calc = $data['discount_rate_calc'];
        $beta_details = $discount_calc['beta_details'];
        $current_price = $data['current_price'];
        $discount_pct = ($value_per_share > 0) ? (($value_per_share - $current_price) / $value_per_share) * 100 : 0;
    
        // Table 1: Main Valuation Inputs
        $output = '<h4>Valuation</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Source</th><th>Value</th></tr></thead><tbody>';
        $output .= '<tr><td>Valuation Model</td><td></td><td>' . esc_html($data['model_name']) . '</td></tr>';
        $output .= '<tr><td>Book Value of Equity per Share</td><td>Latest Annual Report</td><td>$' . number_format($data['current_book_value_per_share'], 1) . '</td></tr>';
        $output .= '<tr><td>Discount Rate (Cost of Equity)</td><td>See below</td><td>' . number_format($data['cost_of_equity'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td>Perpetual Growth Rate</td><td>' . esc_html($discount_calc['risk_free_rate_source']) . '</td><td>' . number_format($data['terminal_growth_rate'] * 100, 1) . '%</td></tr>';
        $output .= '</tbody></table>';

        // Table 2: Discount Rate Calculation
        $output .= '<h4>Discount Rate</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Calculation/ Source</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Risk-Free Rate</td><td>' . esc_html($discount_calc['risk_free_rate_source']) . '</td><td>' . number_format($discount_calc['risk_free_rate'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td>Equity Risk Premium</td><td>' . esc_html($discount_calc['erp_source']) . '</td><td>' . number_format($discount_calc['equity_risk_premium'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td><strong>Discount Rate/ Cost of Equity</strong></td><td><strong>' . esc_html($discount_calc['cost_of_equity_calc']) . '</strong></td><td><strong>' . number_format($data['inputs']['cost_of_equity'] * 100, 1) . '%</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table for Levered Beta Calculation
        if (isset($beta_details['unlevered_beta_avg'])) {
            $output .= '<h4>Levered Beta Calculation</h4>';
            $output .= '<table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Calculation/ Source</th><th>Result</th></tr></thead><tbody>';
            $output .= '<tr><td>Unlevered Beta</td><td>Damodaran Industry Average</td><td>' . number_format($beta_details['unlevered_beta_avg'], 3) . '</td></tr>';
            if (isset($beta_details['relevered_beta_calc'])) {
                 $output .= '<tr><td>Re-levered Beta</td><td>' . esc_html($beta_details['relevered_beta_calc']) . '</td><td>' . number_format($beta_details['unconstrained_levered_beta'], 3) . '</td></tr>';
            }
            $output .= '<tr><td>Levered Beta</td><td>Levered Beta limited to 0.8 to 2.0<br>(practical range for a stable firm)</td><td>' . number_format($discount_calc['beta'], 3) . '</td></tr>';
        $output .= '</tbody></table>';
        }
        
        // Table 3: Excess Returns Calculation
        $output .= '<h4>Value of Excess Returns</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Excess Returns</td><td>(Return on Equity - Cost of Equity) x (Book Value of Equity per share)<br>= (' . number_format($data['roe'] * 100, 1) . '% - ' . number_format($data['cost_of_equity'] * 100, 1) . '%) x $' . number_format($data['current_book_value_per_share'], 1) . '</td><td>$' . number_format($data['excess_return_per_share'], 1) . '</td></tr>';
        $output .= '<tr><td>Terminal Value of Excess Returns</td><td>Excess Returns / (Cost of Equity - Expected Growth Rate)<br>= $' . number_format($data['excess_return_per_share'], 1) . ' / (' . number_format($data['cost_of_equity'] * 100, 1) . '% - ' . number_format($data['terminal_growth_rate'] * 100, 1) . '%)</td><td>$' . number_format($data['terminal_value_of_excess_returns_per_share'], 1) . '</td></tr>';
        $output .= '<tr><td><strong>Value of Equity</strong></td><td><strong>Book Value per share + Terminal Value of Excess Returns</strong><br><strong>$' . number_format($data['current_book_value_per_share'], 1) . ' + $' . number_format($data['terminal_value_of_excess_returns_per_share'], 1) . '</strong></td><td><strong>$' . number_format($value_per_share, 1) . '</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table 4: Discount to Share Price
        $output .= '<h4>Discount to Share Price</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Value per share (USD)</td><td>From above.</td><td>$' . number_format($value_per_share, 1) . '</td></tr>';
        $output .= '<tr><td>Current discount</td><td>Discount to share price of $' . number_format($current_price, 1) . '<br>= ($' . number_format($value_per_share, 1) . ' - $' . number_format($current_price, 1) . ') / $' . number_format($current_price, 1) . '</td><td>' . number_format($discount_pct, 1) . '%</td></tr>';
        $output .= '</tbody></table>';

        return $output;
    }

    private function create_metric_card($title, $value, $prefix = '', $custom_class = '', $use_large_number_format = false) {
        $formatted_value = 'N/A';
        if (is_numeric($value)) {
            if ($use_large_number_format) {
                $final_prefix = ($title === 'Shares Outstanding') ? '' : $prefix;
                $formatted_value = $this->format_large_number($value, $final_prefix, 1);
            } else {
                $temp_val = number_format((float)$value, 1);
                if ($prefix === '$') { $formatted_value = $prefix . $temp_val; } 
                elseif ($prefix === '%') { $formatted_value = $temp_val . $prefix; } 
                else { $formatted_value = $temp_val; }
            }
        } elseif (!empty($value)) { $formatted_value = $value; }
        return '<div class="jtw-metric-card ' . esc_attr($custom_class) . '"><h3 class="jtw-metric-title">' . esc_html($title) . '</h3><p class="jtw-metric-value">' . esc_html($formatted_value) . '</p></div>';
    }

    private function create_interactive_metric_card($title, $value, $data_attrs) {
        $formatted_value = is_numeric($value) ? number_format($value, 1) . 'x' : 'N/A';
        $data_attr_string = '';
        foreach ($data_attrs as $key => $val) {
            $data_attr_string .= ' data-' . esc_attr($key) . '="' . esc_attr($val) . '"';
        }
        
        return '<div class="jtw-metric-card is-interactive" ' . $data_attr_string . '><h3 class="jtw-metric-title">' . esc_html($title) . '</h3><p class="jtw-metric-value">' . esc_html($formatted_value) . '</p></div>';
    }

    private function process_historical_table_data($company_data) {
        $all_data = [];
        $current_year = date('Y');
    
        // 1. Get all annual report dates to create a master list of years
        $all_years = [];
        $report_keys = ['income_statement', 'balance_sheet', 'cash_flow', 'earnings'];
        foreach ($report_keys as $key) {
            if (!is_wp_error($company_data[$key]) && isset($company_data[$key]['annualReports'])) {
                foreach ($company_data[$key]['annualReports'] as $report) {
                    $year = substr($report['fiscalDateEnding'], 0, 4);
                    if ($year < $current_year) {
                        $all_years[$year] = true;
                    }
                }
            }
        }
        if (isset($company_data['earnings']['annualEarnings'])) {
             foreach ($company_data['earnings']['annualEarnings'] as $report) {
                $year = substr($report['fiscalDateEnding'], 0, 4);
                if ($year < $current_year) {
                    $all_years[$year] = true;
                }
            }
        }
        
        $years = array_keys($all_years);
        sort($years); // Sort years in ascending order for chronological display
        $years = array_slice($years, -10); // Limit to the last 10 years
    
        // 2. Pre-process daily price data to find annual high/low
        $prices_by_year = [];
        if (!is_wp_error($company_data['daily_data']) && isset($company_data['daily_data']['Time Series (Daily)'])) {
            foreach ($company_data['daily_data']['Time Series (Daily)'] as $date => $day_data) {
                $year = substr($date, 0, 4);
                if (!isset($prices_by_year[$year])) {
                    $prices_by_year[$year] = ['high' => -INF, 'low' => INF, 'sum' => 0, 'count' => 0];
                }
                $high = (float)$day_data['2. high'];
                $low = (float)$day_data['3. low'];
                $close = (float)$day_data['4. close'];

                if ($high > $prices_by_year[$year]['high']) {
                    $prices_by_year[$year]['high'] = $high;
                }
                if ($low < $prices_by_year[$year]['low']) {
                    $prices_by_year[$year]['low'] = $low;
                }
                $prices_by_year[$year]['sum'] += $close;
                $prices_by_year[$year]['count']++;
            }
        }
    
        // 3. Create a map for each financial statement for quick lookup
        $income_map = [];
        if (!is_wp_error($company_data['income_statement']) && isset($company_data['income_statement']['annualReports'])) {
            foreach ($company_data['income_statement']['annualReports'] as $report) {
                $income_map[substr($report['fiscalDateEnding'], 0, 4)] = $report;
            }
        }
    
        $balance_map = [];
        if (!is_wp_error($company_data['balance_sheet']) && isset($company_data['balance_sheet']['annualReports'])) {
            foreach ($company_data['balance_sheet']['annualReports'] as $report) {
                $balance_map[substr($report['fiscalDateEnding'], 0, 4)] = $report;
            }
        }
    
        $cashflow_map = [];
        if (!is_wp_error($company_data['cash_flow']) && isset($company_data['cash_flow']['annualReports'])) {
            foreach ($company_data['cash_flow']['annualReports'] as $report) {
                $cashflow_map[substr($report['fiscalDateEnding'], 0, 4)] = $report;
            }
        }
    
        $earnings_map = [];
        if (isset($company_data['earnings']['annualEarnings'])) {
            foreach ($company_data['earnings']['annualEarnings'] as $report) {
                $earnings_map[substr($report['fiscalDateEnding'], 0, 4)] = $report;
            }
        }

        // 4. Populate the data for each year
        foreach ($years as $year) {
            $income_report = $income_map[$year] ?? [];
            $balance_report = $balance_map[$year] ?? [];
            $cashflow_report = $cashflow_map[$year] ?? [];
            $earnings_report = $earnings_map[$year] ?? [];
            $price_data = $prices_by_year[$year] ?? ['high' => null, 'low' => null, 'sum' => 0, 'count' => 0];
    
            $shares = (float)($balance_report['commonStockSharesOutstanding'] ?? 0);
            $revenue = (float)($income_report['totalRevenue'] ?? 0);
            $eps = (float)($earnings_report['reportedEPS'] ?? 0);
            $op_cash_flow = (float)($cashflow_report['operatingCashflow'] ?? 0);
            $capex = (float)($cashflow_report['capitalExpenditures'] ?? 0);
            $fcf = $op_cash_flow - abs($capex);
            $net_income = (float)($income_report['netIncome'] ?? 0);
            $shareholder_equity = (float)($balance_report['totalShareholderEquity'] ?? 0);
            $ebit = (float)($income_report['ebit'] ?? 0);
            $total_assets = (float)($balance_report['totalAssets'] ?? 0);
            $current_liabilities = (float)($balance_report['totalCurrentLiabilities'] ?? 0);
            $total_capital = $total_assets - $current_liabilities;
    
            $all_data[$year] = [
                'year' => $year,
                'price_high' => $price_data['high'] === -INF ? null : $price_data['high'],
                'price_low' => $price_data['low'] === INF ? null : $price_data['low'],
                'avg_price' => ($price_data['high'] !== -INF && $price_data['low'] !== INF) ? ($price_data['high'] + $price_data['low']) / 2 : null,
                'revenue_ps' => $shares > 0 ? $revenue / $shares : 0,
                'eps' => $eps,
                'cash_flow_ps' => $shares > 0 ? $fcf / $shares : 0,
                'book_value_ps' => $shares > 0 ? $shareholder_equity / $shares : 0,
                'shares_outstanding' => $shares,
                'net_profit_margin' => $revenue > 0 ? ($net_income / $revenue) * 100 : 0,
                'return_on_equity' => $shareholder_equity > 0 ? ($net_income / $shareholder_equity) * 100 : 0,
                'return_on_capital' => $total_capital > 0 ? ($ebit / $total_capital) * 100 : 0,
                'shareholder_equity' => $shareholder_equity,
            ];
        }
    
        return array_values($all_data); // Return as a simple array for JSON encoding
    }

    private function build_historical_data_section_html($table_data) {
        $output = '<div class="jtw-content-section" id="section-historical-data-content">';
        $output .= '<h4>' . esc_html__('Data Trends', 'journey-to-wealth') . '</h4>';
    
        // Combined Chart and Table Wrapper
        $output .= '<div class="jtw-historical-combined-wrapper">';
    
        // Chart Container
        $chart_id = 'jtw-historical-chart-' . uniqid();
        $output .= '<div class="jtw-historical-chart-container">';
        $output .= '<canvas id="' . esc_attr($chart_id) . '"></canvas>';
        $output .= '</div>';
    
        // Data Table
        $output .= '<div class="jtw-historical-table-wrapper">';
        $output .= '<table class="jtw-historical-table">';
        
        // Define the rows for the pivoted table
        $metrics = [
            'revenue_ps' => 'Revenue / Share',
            'eps' => 'EPS',
            'cash_flow_ps' => 'FCF / Share',
            'book_value_ps' => 'Book Value / Share',
            'shares_outstanding' => 'Shares (M)',
            'net_profit_margin' => 'Net Profit Margin',
            'return_on_equity' => 'Return on Equity',
            'return_on_capital' => 'Return on Capital',
            'shareholder_equity' => 'Shareholder Equity',
        ];
    
        // Table Header (Years)
        $output .= '<thead><tr><th>Metric</th>';
        foreach ($table_data as $data_point) {
            $output .= '<th>' . esc_html($data_point['year']) . '</th>';
        }
        $output .= '</tr></thead>';
    
        // Table Body (Metrics as rows)
        $output .= '<tbody>';
        foreach ($metrics as $key => $label) {
            $output .= '<tr>';
            $output .= '<td>' . esc_html($label) . '</td>'; // First cell is the metric label
            foreach ($table_data as $data_point) {
                $value = $data_point[$key] ?? 'N/A';
                $formatted_value = 'N/A';
                if (is_numeric($value)) {
                    switch ($key) {
                        case 'shares_outstanding':
                            $formatted_value = $this->format_large_number($value, '', 1);
                            break;
                        case 'shareholder_equity':
                            $formatted_value = ($value != 0) ? $this->format_large_number($value, '$', 1) : 'N/A';
                            break;
                        case 'net_profit_margin':
                        case 'return_on_equity':
                        case 'return_on_capital':
                            $formatted_value = number_format($value, 1) . '%';
                            break;
                        default:
                            $formatted_value = '$' . number_format($value, 1);
                    }
                }
                $output .= '<td>' . esc_html($formatted_value) . '</td>';
            }
            $output .= '</tr>';
        }
        $output .= '</tbody>';
    
        $output .= '</table>';
        $output .= '</div>'; // End table-wrapper
        
        $output .= '</div>'; // End combined-wrapper
    
        // Pass data to JS
        $output .= "<script type='application/json' id='jtw-historical-data-json' data-chart-id='" . esc_attr($chart_id) . "'>";
        $output .= json_encode($table_data);
        $output .= "</script>";
    
        $output .= '</div>';
        return $output;
    }
}
