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
        wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', array('jquery'), '3.9.1', true );
        wp_enqueue_script( 'chartjs-adapter-date-fns', 'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js', array('chartjs'), '1.1.0', true );
        // Enqueue the datalabels plugin and make it dependent on chartjs
        wp_enqueue_script( 'chartjs-plugin-datalabels', 'https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.1.0/dist/chartjs-plugin-datalabels.min.js', array('chartjs'), '2.1.0', true );
        
        $script_path = plugin_dir_path( __FILE__ ) . 'assets/js/public-scripts.js';
        $script_version = file_exists($script_path) ? $this->version . '.' . filemtime( $script_path ) : $this->version;
        // Add the datalabels plugin as a dependency for the main script
        wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'assets/js/public-scripts.js', array( 'jquery', 'chartjs', 'chartjs-adapter-date-fns', 'chartjs-plugin-datalabels' ), $script_version, true );
        
        $analysis_page_slug = get_option('jtw_analysis_page_slug', 'stock-valuation-analysis');
        $analysis_page_url = site_url( '/' . $analysis_page_slug . '/' );

        wp_localize_script( $this->plugin_name, 'jtw_public_params', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'section_nonce' => wp_create_nonce('jtw_fetch_section_nonce'),
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
            $output .= '<nav class="jtw-anchor-nav"><ul>';

            // Company Overview Major Section
            $output .= '<li class="jtw-nav-major-section has-link"><a href="#section-overview" class="jtw-anchor-link active">' . esc_html__('Company Overview', 'journey-to-wealth') . '</a></li>';

            // Performance Major Section
            $output .= '<li class="jtw-nav-major-section">' . esc_html__('Performance', 'journey-to-wealth') . '</li>';
            $output .= '<li class="jtw-nav-minor-section"><a href="#section-historical-data" class="jtw-anchor-link">' . esc_html__('Data Trends', 'journey-to-wealth') . '</a></li>';
            $output .= '<li class="jtw-nav-minor-section"><a href="#section-past-performance" class="jtw-anchor-link">' . esc_html__('Visual Trends', 'journey-to-wealth') . '</a></li>';

            // Valuation Major Section
            $output .= '<li class="jtw-nav-major-section">' . esc_html__('Valuation', 'journey-to-wealth') . '</li>';
            $output .= '<li class="jtw-nav-minor-section"><a href="#section-key-metrics-ratios" class="jtw-anchor-link">' . esc_html__('Key Metrics & Ratios', 'journey-to-wealth') . '</a></li>';
            $output .= '<li class="jtw-nav-minor-section"><a href="#section-intrinsic-valuation" class="jtw-anchor-link">' . esc_html__('Fair Value Analysis', 'journey-to-wealth') . '</a></li>';

            $output .= '</ul></nav>';
            
            $output .= '<main class="jtw-content-main">';
            $output .= '<div id="jtw-currency-notice-placeholder"></div>';
            
            // Company Overview Major Group
            $output .= '<div class="jtw-major-content-group">';
            $output .= '<h2>' . esc_html__('Company Overview', 'journey-to-wealth') . '</h2>';
            $output .= '<div id="section-overview" class="jtw-content-section-placeholder" data-section="overview"></div>';
            $output .= '</div>';

            // Performance Major Group
            $output .= '<div class="jtw-major-content-group">';
            $output .= '<h2>' . esc_html__('Performance', 'journey-to-wealth') . '</h2>';
            $output .= '<div id="section-historical-data" class="jtw-content-section-placeholder" data-section="historical-data"></div>';
            $output .= '<div id="section-past-performance" class="jtw-content-section-placeholder" data-section="past-performance"></div>';
            $output .= '</div>';

            // Valuation Major Group
            $output .= '<div class="jtw-major-content-group">';
            $output .= '<h2>' . esc_html__('Valuation', 'journey-to-wealth') . '</h2>';
            $output .= '<div id="section-key-metrics-ratios" class="jtw-content-section-placeholder" data-section="key-metrics-ratios"></div>';
            $output .= '<div id="section-intrinsic-valuation" class="jtw-content-section-placeholder" data-section="intrinsic-valuation"></div>';
            $output .= '</div>';
            
            $output .= '</main>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '</div>';
        return $output;
    }

    public function ajax_symbol_search() {
        check_ajax_referer('jtw_symbol_search_nonce_action', 'jtw_symbol_search_nonce');
    
        $keywords = isset($_POST['keywords']) ? sanitize_text_field($_POST['keywords']) : '';
        if (empty($keywords)) {
            wp_send_json_error(['matches' => []]);
            return;
        }
    
        $api_key = get_option('jtw_av_api_key');
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API Key not configured.']);
            return;
        }
    
        $av_client = new Alpha_Vantage_Client($api_key);
        $results = $av_client->search_symbols($keywords);
    
        if (is_wp_error($results) || empty($results)) {
            wp_send_json_success(['matches' => []]);
            return;
        }
    
        $matches = array_map(function($item) {
            return [
                'ticker'   => $item['1. symbol'],
                'name'     => $item['2. name'],
                'exchange' => $item['4. region'],
                'locale'   => strtolower(substr($item['8. currency'], 0, 2)),
                'icon_url' => '',
            ];
        }, $results);
    
        $limited_matches = array_slice($matches, 0, 3);
    
        wp_send_json_success(['matches' => $limited_matches]);
    }

    private function convert_financial_data(&$report_data, $exchange_rate) {
        if ($exchange_rate == 1.0 || !is_array($report_data)) {
            return;
        }

        $report_types = ['annualReports', 'quarterlyReports', 'annualEarnings', 'quarterlyEarnings'];
        $keys_to_skip = ['fiscalDateEnding', 'commonStockSharesOutstanding'];

        foreach ($report_types as $type) {
            if (isset($report_data[$type])) {
                foreach ($report_data[$type] as $key => &$report) {
                    foreach ($report as $field => &$value) {
                        if (is_numeric($value) && !in_array($field, $keys_to_skip)) {
                            $value = (float)$value * $exchange_rate;
                        }
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
            if(is_wp_error($overview) || !isset($overview['Symbol'])) { return new WP_Error('api_error', 'Could not retrieve company overview data. The symbol may be invalid or the API limit may have been reached.'); }

            $income_statement = $av_client->get_income_statement($ticker);
            if(is_wp_error($income_statement) || !isset($income_statement['symbol'])) { return new WP_Error('api_error', 'Could not retrieve income statement data.'); }
            
            $balance_sheet = $av_client->get_balance_sheet($ticker);
            if(is_wp_error($balance_sheet) || !isset($balance_sheet['symbol'])) { return new WP_Error('api_error', 'Could not retrieve balance sheet data.'); }

            $cash_flow = $av_client->get_cash_flow_statement($ticker);
            if(is_wp_error($cash_flow) || !isset($cash_flow['symbol'])) { return new WP_Error('api_error', 'Could not retrieve cash flow data.'); }

            $earnings = $av_client->get_earnings_data($ticker);
            if(is_wp_error($earnings) || !isset($earnings['symbol'])) { return new WP_Error('api_error', 'Could not retrieve earnings data.'); }

            $quote = $av_client->get_global_quote($ticker);
            if(is_wp_error($quote)) { return $quote; }

            $daily_data = $av_client->get_daily_adjusted($ticker);
            $treasury_yield = $av_client->get_treasury_yield();

            $original_currency = 'USD';
            if (isset($income_statement['annualReports'][0]['reportedCurrency'])) {
                $currency_value = $income_statement['annualReports'][0]['reportedCurrency'];
                if (!empty($currency_value) && strcasecmp(trim($currency_value), 'none') !== 0) {
                    $original_currency = strtoupper(trim($currency_value));
                }
            }

            $exchange_rate = 1.0;
            $currency_notice = '';

            if ($original_currency !== 'USD') {
                $rate_data = $av_client->get_currency_exchange_rate($original_currency, 'USD');
                if (!is_wp_error($rate_data) && isset($rate_data['Realtime Currency Exchange Rate']['5. Exchange Rate'])) {
                    $exchange_rate = (float)$rate_data['Realtime Currency Exchange Rate']['5. Exchange Rate'];
                    $currency_notice = sprintf(
                        '<div class="jtw-currency-notice notice notice-info inline"><p><strong>Note:</strong> All financial data has been converted from %s to USD at a rate of 1 %s = %s USD.</p></div>',
                        esc_html($original_currency),
                        esc_html($original_currency),
                        esc_html(number_format($exchange_rate, 4))
                    );

                    $this->convert_financial_data($income_statement, $exchange_rate);
                    $this->convert_financial_data($balance_sheet, $exchange_rate);
                    $this->convert_financial_data($cash_flow, $exchange_rate);
                    $this->convert_financial_data($earnings, $exchange_rate);
                    
                } else {
                    return new WP_Error('currency_error', 'Could not retrieve currency exchange rate to convert financial data to USD.');
                }
            }

            $company_data = [
                'income_statement' => $income_statement,
                'balance_sheet'    => $balance_sheet,
                'cash_flow'        => $cash_flow,
                'earnings'         => $earnings,
                'overview'         => $overview,
                'quote'            => $quote,
                'daily_data'       => $daily_data,
                'treasury_yield'   => $treasury_yield,
                'currency_notice'  => $currency_notice,
            ];

            set_transient($transient_key, $company_data, HOUR_IN_SECONDS);
        }
        
        return $company_data;
    }

    public function ajax_fetch_section_data() {
        check_ajax_referer('jtw_fetch_section_nonce', 'nonce');

        $ticker = isset($_POST['ticker']) ? sanitize_text_field(strtoupper($_POST['ticker'])) : '';
        $section = isset($_POST['section']) ? sanitize_key($_POST['section']) : '';

        if (empty($ticker) || empty($section)) {
            wp_send_json_error(['message' => 'Missing required parameters.']);
        }

        if(!defined('MEPR_VERSION') && file_exists(WP_PLUGIN_DIR . '/memberpress/memberpress.php')) {
            require_once(WP_PLUGIN_DIR . '/memberpress/memberpress.php');
        }
        $memberpress_rules = [ 'overview' => 0, 'historical-data' => 0, 'past-performance' => 0, 'key-metrics-ratios' => 2271, 'intrinsic-valuation' => 2273 ];
        $required_rule_id = $memberpress_rules[$section] ?? null;
        $has_access = false;
        $user_id = get_current_user_id();
        if ($required_rule_id === null) { $has_access = false; 
        } elseif ($required_rule_id == 0) { $has_access = is_user_logged_in();
        } elseif ($user_id > 0 && class_exists('MeprUser')) {
            global $wpdb;
            $mepr_user = new MeprUser($user_id);
            $access_conditions_table = $wpdb->prefix . 'mepr_rule_access_conditions';
            $required_memberships = $wpdb->get_col($wpdb->prepare("SELECT access_condition FROM {$access_conditions_table} WHERE rule_id = %d AND access_type = 'membership'", $required_rule_id));
            if (!empty($required_memberships)) {
                $user_active_memberships = $mepr_user->active_product_subscriptions('ids');
                $common_memberships = array_intersect($user_active_memberships, $required_memberships);
                if (!empty($common_memberships)) { $has_access = true; }
            }
        }
        if (!$has_access) {
            $upgrade_url = get_permalink(get_option('mepr_account_page_id'));
            $html = '<div class="jtw-paywall"><h4>' . esc_html__('Upgrade Required', 'journey-to-wealth') . '</h4><p>' . esc_html__('This section is available for premium members. Please upgrade your plan to unlock this content.', 'journey-to-wealth') . '</p><a href="' . esc_url($upgrade_url) . '" class="jtw-button-primary">' . esc_html__('Upgrade Now', 'journey-to-wealth') . '</a></div>';
            wp_send_json_success(['html' => $html, 'paywall' => true]);
            return;
        }
        
        $company_data = $this->get_and_prepare_company_data($ticker);
        if (is_wp_error($company_data)) {
            wp_send_json_error(['message' => $company_data->get_error_message()]);
            return;
        }

        $html = '';
        $json_response = [];

        if ($section === 'overview' && !empty($company_data['currency_notice'])) {
            $json_response['currency_notice'] = $company_data['currency_notice'];
        }

        switch ($section) {
            case 'overview':
                if (!is_wp_error($company_data['overview']) && !empty($company_data['overview']['Symbol'])) {
                    $this->store_and_map_discovered_company($ticker, $company_data['overview']['Industry'], $company_data['overview']['Sector']);
                    $html = $this->build_overview_section_html($company_data['overview'], $company_data['quote']);
                }
                break;

            case 'historical-data':
                $table_data = $this->process_historical_table_data($company_data);
                $html = $this->build_historical_data_section_html($table_data);
                break;

            case 'past-performance':
                $historical_data = $this->process_av_historical_data($company_data['daily_data'], $company_data['income_statement'], $company_data['balance_sheet'], $company_data['cash_flow'], $company_data['earnings']);
                $html = $this->build_past_performance_section_html($historical_data);
                break;
            
            case 'key-metrics-ratios':
                $overview = $company_data['overview'];
                $quote = $company_data['quote'];
                $stock_price = !is_wp_error($quote) ? (float)($quote['05. price'] ?? 0) : 0;
            
                // Trailing P/E and related data
                $trailing_pe_ratio = isset($overview['PERatio']) && $overview['PERatio'] !== 'None' ? (float)$overview['PERatio'] : 'N/A';
                $trailing_eps = isset($overview['EPS']) && $overview['EPS'] !== 'None' ? (float)$overview['EPS'] : 'N/A';
            
                // Forward P/E and related data
                $forward_pe_ratio = isset($overview['ForwardPE']) && $overview['ForwardPE'] !== 'None' ? (float)$overview['ForwardPE'] : 'N/A';
                $forward_eps = 'N/A';
                if (is_numeric($forward_pe_ratio) && $forward_pe_ratio > 0 && $stock_price > 0) {
                    $forward_eps = $stock_price / $forward_pe_ratio;
                }
            
                $key_metrics_data = [
                    'trailingPeRatio' => $trailing_pe_ratio,
                    'forwardPeRatio'  => $forward_pe_ratio,
                    'pbRatio'         => isset($overview['PriceToBookRatio']) && $overview['PriceToBookRatio'] !== 'None' ? (float)$overview['PriceToBookRatio'] : 'N/A',
                    'psRatio'         => isset($overview['PriceToSalesRatioTTM']) && $overview['PriceToSalesRatioTTM'] !== 'None' ? (float)$overview['PriceToSalesRatioTTM'] : 'N/A',
                    'evToRevenue'     => isset($overview['EVToRevenue']) && $overview['EVToRevenue'] !== 'None' ? (float)$overview['EVToRevenue'] : 'N/A',
                    'evToEbitda'      => isset($overview['EVToEBITDA']) && $overview['EVToEBITDA'] !== 'None' ? (float)$overview['EVToEBITDA'] : 'N/A',
                ];
            
                // PEG/PEGY Calculations
                $peg_value_from_api = isset($overview['PEGRatio']) && $overview['PEGRatio'] !== 'None' ? (float)$overview['PEGRatio'] : 'N/A';
                $growth_rate = 'N/A';
                if (is_numeric($trailing_pe_ratio) && is_numeric($peg_value_from_api) && $peg_value_from_api > 0) {
                    $growth_rate = ($trailing_pe_ratio / $peg_value_from_api);
                }
                $final_growth_rate = is_numeric($growth_rate) ? $growth_rate : 5.0;
                $dividend_yield_percent = isset($overview['DividendYield']) && is_numeric($overview['DividendYield']) ? (float)$overview['DividendYield'] * 100 : 0;
            
                // Trailing PEG/PEGY
                $trailing_peg = 'N/A';
                if (is_numeric($trailing_pe_ratio) && $trailing_pe_ratio > 0 && $final_growth_rate > 0) {
                    $trailing_peg = $trailing_pe_ratio / $final_growth_rate;
                }
                $trailing_pegy = 'N/A';
                if (is_numeric($trailing_pe_ratio) && ($final_growth_rate + $dividend_yield_percent) > 0) {
                    $trailing_pegy = $trailing_pe_ratio / ($final_growth_rate + $dividend_yield_percent);
                }
            
                // Forward PEG/PEGY
                $forward_peg = 'N/A';
                if (is_numeric($forward_pe_ratio) && $forward_pe_ratio > 0 && $final_growth_rate > 0) {
                    $forward_peg = $forward_pe_ratio / $final_growth_rate;
                }
                $forward_pegy = 'N/A';
                if (is_numeric($forward_pe_ratio) && ($final_growth_rate + $dividend_yield_percent) > 0) {
                    $forward_pegy = $forward_pe_ratio / ($final_growth_rate + $dividend_yield_percent);
                }
            
                $peg_pegy_data = [
                    'trailing_peg' => $trailing_peg,
                    'trailing_pegy' => $trailing_pegy,
                    'forward_peg' => $forward_peg,
                    'forward_pegy' => $forward_pegy,
                    'defaultGrowth' => $final_growth_rate,
                    'dividendYield' => $dividend_yield_percent
                ];
            
                $html = $this->build_key_metrics_ratios_section_html($key_metrics_data, $peg_pegy_data, $stock_price, $trailing_eps, $forward_eps, $overview);
                break;

            case 'intrinsic-valuation':
                $latest_price = !is_wp_error($company_data['quote']) ? (float)($company_data['quote']['05. price'] ?? 0) : 0;
                $valuation_data = $this->get_valuation_results($company_data['overview'], $company_data['income_statement'], $company_data['balance_sheet'], $company_data['cash_flow'], $company_data['earnings'], $company_data['treasury_yield'], $latest_price, $company_data['daily_data']);
                
                $valuation_summary = [ 'current_price' => $latest_price, 'fair_value' => 0, 'percentage_diff' => 0 ];
                $valid_models = [];
                foreach ($valuation_data as $result) {
                    if (!is_wp_error($result) && isset($result['intrinsic_value_per_share'])) {
                        $valid_models[] = $result['intrinsic_value_per_share'];
                    }
                }
                
                if (!empty($valid_models)) {
                    $valuation_summary['fair_value'] = array_sum($valid_models) / count($valid_models);
                    if ($latest_price > 0 && $valuation_summary['fair_value'] > 0) {
                        $valuation_summary['percentage_diff'] = (($latest_price - $valuation_summary['fair_value']) / $valuation_summary['fair_value']) * 100;
                    }
                }

                $html = $this->build_intrinsic_valuation_section_html($valuation_data, $valuation_summary, $company_data['overview']);
                break;
        }

        if (empty($html)) {
            wp_send_json_error(['message' => 'Could not generate content for this section. The company may not support this type of analysis.']);
        } else {
            $json_response['html'] = $html;
            wp_send_json_success($json_response);
        }
    }

    private function get_valuation_results($overview, $income_statement, $balance_sheet, $cash_flow, $earnings, $treasury_yield, $latest_price, $daily_data) {
        $valuation_data = [];
        $erp_setting = (float) get_option('jtw_erp_setting', '5.0');
        $erp_decimal = $erp_setting / 100;
        $tax_rate_setting = (float) get_option('jtw_tax_rate_setting', '21.0');
        $tax_rate_decimal = $tax_rate_setting / 100;
        
        if (!is_wp_error($balance_sheet) && isset($balance_sheet['annualReports'][0]['commonStockSharesOutstanding'])) {
            $authoritative_shares = (float)$balance_sheet['annualReports'][0]['commonStockSharesOutstanding'];
            if ($authoritative_shares > 0) {
                $overview['SharesOutstanding'] = $authoritative_shares;
            }
        }

        $beta_details = $this->calculate_levered_beta($overview['Symbol'], $balance_sheet, $overview['MarketCapitalization'], $tax_rate_decimal);
        $levered_beta = $beta_details['levered_beta'];
    
        $industry_upper = strtoupper($overview['Industry']);
        $sector_upper = strtoupper($overview['Sector']);
    
        $is_reit = strpos($industry_upper, 'REIT') !== false || strpos($sector_upper, 'REAL ESTATE') !== false;
        $is_bank = strpos($industry_upper, 'BANK') !== false;
        $is_insurance = strpos($industry_upper, 'INSURANCE') !== false;
        $is_financial_services = strpos($sector_upper, 'FINANCIAL SERVICES') !== false;
    
        if ($is_reit) {
            $affo_model = new Journey_To_Wealth_AFFO_Model($erp_decimal, $levered_beta);
            $result = $affo_model->calculate($overview, $income_statement, $cash_flow, $treasury_yield, $latest_price, $beta_details);
            if (!is_wp_error($result)) {
                $valuation_data['AFFO Model'] = $result;
            }
        } elseif ($is_bank || $is_insurance || $is_financial_services) {
            $excess_return_model = new Journey_To_Wealth_Excess_Return_Model($erp_decimal, $levered_beta);
            $result = $excess_return_model->calculate($overview, $income_statement, $balance_sheet, $treasury_yield, $latest_price, $beta_details);
            if (!is_wp_error($result)) {
                $valuation_data['Excess Return Model'] = $result;
            }
        } else {
            $dcf_model = new Journey_To_Wealth_DCF_Model($erp_decimal, $levered_beta);
            $result = $dcf_model->calculate($overview, $income_statement, $balance_sheet, $cash_flow, $earnings, $treasury_yield, $latest_price, $beta_details);
            if (!is_wp_error($result)) {
                 $valuation_data['DCF Model'] = $result;
            }
        }
    
        if (empty($valuation_data) && isset($overview['DividendPerShare']) && (float)$overview['DividendPerShare'] > 0) {
            $ddm_model = new Journey_To_Wealth_DDM_Model($erp_decimal, $levered_beta);
            $ddm_result = $ddm_model->calculate($overview, $treasury_yield, $latest_price, $daily_data, $beta_details);
            if (!is_wp_error($ddm_result)) {
                $valuation_data['Dividend Discount Model'] = $ddm_result;
            }
        }
    
        return $valuation_data;
    }
    
    private function store_and_map_discovered_company($ticker, $industry_name, $sector_name) {
        if (empty($ticker) || empty($industry_name)) {
            return;
        }
    
        $discovered = get_option('jtw_discovered_companies', []);
        if (!is_array($discovered)) {
            $discovered = [];
        }
        
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
    
        $debug_data = [
            'levered_beta' => 1.0,
            'unlevered_beta_avg' => null,
            'debt_to_equity' => null,
            'tax_rate' => $tax_rate,
            'mapped_damodaran_industries' => [],
            'beta_source' => 'Default'
        ];
    
        $mapping_table = $wpdb->prefix . 'jtw_company_mappings';
        $beta_table = $wpdb->prefix . 'jtw_industry_betas';
        
        $unlevered_betas = $wpdb->get_col($wpdb->prepare(
            "SELECT b.unlevered_beta
             FROM $mapping_table as m
             JOIN $beta_table as b ON m.damodaran_industry_id = b.id
             WHERE m.ticker = %s",
            $ticker
        ));
    
        if (empty($unlevered_betas)) {
            return $debug_data;
        }

        $debug_data['mapped_damodaran_industries'] = $wpdb->get_col($wpdb->prepare(
            "SELECT b.industry_name
             FROM $mapping_table as m
             JOIN $beta_table as b ON m.damodaran_industry_id = b.id
             WHERE m.ticker = %s",
            $ticker
        ));
    
        $average_unlevered_beta = array_sum($unlevered_betas) / count($unlevered_betas);
        $debug_data['unlevered_beta_avg'] = $average_unlevered_beta;
        $debug_data['levered_beta'] = $average_unlevered_beta;
        $debug_data['beta_source'] = 'Calculated from Industry Beta';
    
        if (is_wp_error($balance_sheet) || empty($balance_sheet['annualReports'])) {
            return $debug_data;
        }
    
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
    
    private function calculate_av_fcf_yield($cash_flow_data, $market_cap) {
        if (is_wp_error($cash_flow_data) || empty($cash_flow_data['annualReports']) || $market_cap <= 0) { return 'N/A'; }
        $latest_report = $cash_flow_data['annualReports'][0] ?? null;
        if (!$latest_report) return 'N/A';
        $operating_cash_flow = (float)($latest_report['operatingCashflow'] ?? 0);
        $capex = (float)($latest_report['capitalExpenditures'] ?? 0);
        $fcf = $operating_cash_flow - abs($capex);
        return ($fcf / $market_cap) * 100;
    }

    private function process_av_historical_data($daily_data, $income_statement, $balance_sheet, $cash_flow, $earnings) {
        $master_labels_annual = $this->get_master_labels([$income_statement, $balance_sheet, $cash_flow, $earnings], 'annual', 20);
        $master_labels_quarterly = $this->get_master_labels([$income_statement, $balance_sheet, $cash_flow, $earnings], 'quarterly', 16);
        $annual = [
            'price' => $this->process_av_price_data($daily_data),
            'revenue' => $this->extract_av_financial_data($income_statement, 'totalRevenue', 'annual', $master_labels_annual),
            'net_income' => $this->extract_av_financial_data($income_statement, 'netIncome', 'annual', $master_labels_annual),
            'ebitda' => $this->extract_av_financial_data($income_statement, 'ebitda', 'annual', $master_labels_annual),
            'fcf' => $this->extract_av_fcf_data($cash_flow, 'annual', $master_labels_annual),
            'cash_and_debt' => $this->extract_av_cash_and_debt_data($balance_sheet, 'annual', $master_labels_annual),
            'dividend' => $this->aggregate_av_dividend_data($daily_data, 'annual', $master_labels_annual),
            'shares_outstanding' => $this->extract_av_financial_data($balance_sheet, 'commonStockSharesOutstanding', 'annual', $master_labels_annual),
            'expenses' => $this->extract_av_expenses_data($income_statement, 'annual', $master_labels_annual),
            'eps' => $this->extract_av_earnings_data($earnings, 'annual', $master_labels_annual),
        ];
        $quarterly = [
            'price' => $this->process_av_price_data($daily_data),
            'revenue' => $this->extract_av_financial_data($income_statement, 'totalRevenue', 'quarterly', $master_labels_quarterly),
            'net_income' => $this->extract_av_financial_data($income_statement, 'netIncome', 'quarterly', $master_labels_quarterly),
            'ebitda' => $this->extract_av_financial_data($income_statement, 'ebitda', 'quarterly', $master_labels_quarterly),
            'fcf' => $this->extract_av_fcf_data($cash_flow, 'quarterly', $master_labels_quarterly),
            'cash_and_debt' => $this->extract_av_cash_and_debt_data($balance_sheet, 'quarterly', $master_labels_quarterly),
            'dividend' => $this->aggregate_av_dividend_data($daily_data, 'quarterly', $master_labels_quarterly),
            'shares_outstanding' => $this->extract_av_financial_data($balance_sheet, 'commonStockSharesOutstanding', 'quarterly', $master_labels_quarterly),
            'expenses' => $this->extract_av_expenses_data($income_statement, 'quarterly', $master_labels_quarterly),
            'eps' => $this->extract_av_earnings_data($earnings, 'quarterly', $master_labels_quarterly),
        ];
        return ['annual' => $annual, 'quarterly' => $quarterly];
    }
    
    private function get_master_labels($datasets, $type = 'annual', $limit_count = 10) {
        $all_dates = [];
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        $earnings_key = ($type === 'annual') ? 'annualEarnings' : 'quarterlyEarnings';
    
        foreach ($datasets as $dataset) {
            if (is_wp_error($dataset)) continue;
    
            if ($type === 'annual' && isset($dataset[$report_key])) {
                foreach ($dataset[$report_key] as $report) {
                    $all_dates[] = $report['fiscalDateEnding'];
                }
            } elseif ($type === 'quarterly' && isset($dataset[$report_key])) {
                foreach ($dataset[$report_key] as $report) {
                    $all_dates[] = $report['fiscalDateEnding'];
                }
            } elseif ($type === 'annual' && isset($dataset[$earnings_key])) {
                foreach ($dataset[$earnings_key] as $report) {
                    $all_dates[] = $report['fiscalDateEnding'];
                }
            } elseif ($type === 'quarterly' && isset($dataset['quarterlyEarnings'])) {
                foreach ($dataset['quarterlyEarnings'] as $report) {
                    $all_dates[] = $report['fiscalDateEnding'];
                }
            }
        }
    
        $unique_dates = array_unique($all_dates);
        sort($unique_dates);
    
        $limit = -$limit_count;
        $limited_dates = array_slice($unique_dates, $limit);
        
        if ($type === 'annual') {
            $final_labels = [];
            foreach ($limited_dates as $date) {
                $final_labels[] = substr($date, 0, 4);
            }
            return array_values(array_unique($final_labels));
        } else {
            return $limited_dates;
        }
    }

    private function extract_av_financial_data($reports, $key, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), null)];
        if (is_wp_error($reports)) return $data;
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($reports[$report_key])) return $data;
        $data_map = [];
        foreach ($reports[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $data_map[$label] = isset($report[$key]) && is_numeric($report[$key]) ? (float)$report[$key] : null;
        }
        foreach($master_labels as $i => $label) { if(isset($data_map[$label])) { $data['data'][$i] = $data_map[$label]; } }
        return $data;
    }

    private function extract_av_fcf_data($cash_flow_data, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), null)];
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
        $data = ['labels' => $master_labels, 'datasets' => [ ['label' => 'Total Debt', 'data' => array_fill(0, count($master_labels), null)], ['label' => 'Cash & Equivalents', 'data' => array_fill(0, count($master_labels), null)] ]];
        if (is_wp_error($balance_sheet_data)) return $data;
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($balance_sheet_data[$report_key])) return $data;
        $debt_map = []; $cash_map = [];
        foreach ($balance_sheet_data[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $short_term_debt = (float)($report['shortTermDebt'] ?? 0); $long_term_debt = (float)($report['longTermDebt'] ?? 0);
            $debt_map[$label] = $short_term_debt + $long_term_debt;
            $cash_map[$label] = isset($report['cashAndCashEquivalentsAtCarryingValue']) && is_numeric($report['cashAndCashEquivalentsAtCarryingValue']) ? (float)$report['cashAndCashEquivalentsAtCarryingValue'] : null;
        }
        foreach($master_labels as $i => $label) {
            if(isset($debt_map[$label])) $data['datasets'][0]['data'][$i] = $debt_map[$label];
            if(isset($cash_map[$label])) $data['datasets'][1]['data'][$i] = $cash_map[$label];
        }
        return $data;
    }

    private function extract_av_expenses_data($income_statement_data, $type, $master_labels) {
        $data = ['labels' => $master_labels, 'datasets' => [ ['label' => 'SG&A', 'data' => array_fill(0, count($master_labels), null)], ['label' => 'R&D', 'data' => array_fill(0, count($master_labels), null)], ['label' => 'Interest Expense', 'data' => array_fill(0, count($master_labels), null)] ]];
        if (is_wp_error($income_statement_data)) return $data;
        $report_key = ($type === 'annual') ? 'annualReports' : 'quarterlyReports';
        if (!isset($income_statement_data[$report_key])) return $data;
        $sga_map = []; $rnd_map = []; $interest_map = [];
        foreach ($income_statement_data[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $sga_map[$label] = isset($report['sellingGeneralAndAdministrative']) && is_numeric($report['sellingGeneralAndAdministrative']) ? (float)$report['sellingGeneralAndAdministrative'] : null;
            $rnd_map[$label] = isset($report['researchAndDevelopment']) && is_numeric($report['researchAndDevelopment']) ? (float)$report['researchAndDevelopment'] : null;
            $interest_map[$label] = isset($report['interestExpense']) && is_numeric($report['interestExpense']) ? (float)$report['interestExpense'] : null;
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
        $data = ['labels' => $master_labels, 'data' => array_fill(0, count($master_labels), null)];
        if (is_wp_error($earnings)) return $data;
        $report_key = ($type === 'annual') ? 'annualEarnings' : 'quarterlyEarnings';
        if (!isset($earnings[$report_key])) return $data;
        $data_map = [];
        foreach ($earnings[$report_key] as $report) {
            $label = ($type === 'annual') ? substr($report['fiscalDateEnding'], 0, 4) : $report['fiscalDateEnding'];
            $data_map[$label] = isset($report['reportedEPS']) && is_numeric($report['reportedEPS']) ? (float)$report['reportedEPS'] : null;
        }
        foreach($master_labels as $i => $label) { if(isset($data_map[$label])) { $data['data'][$i] = $data_map[$label]; } }
        return $data;
    }

    private function process_av_price_data($daily_data) {
        $data = ['labels' => [], 'data' => []];
        if (is_wp_error($daily_data) || !isset($daily_data['Time Series (Daily)'])) return $data;
        $time_series = array_slice($daily_data['Time Series (Daily)'], 0, 252 * 20, true); // 20 years of daily data
        $time_series = array_reverse($time_series, true);
        foreach($time_series as $date => $day_data) { $data['labels'][] = $date; $data['data'][] = (float)$day_data['4. close']; }
        return $data;
    }
    
    private function format_large_number($number, $prefix = '$', $decimals = 2) {
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
        $description = $overview['Description'] ?? 'No company description available.';
        $stock_price = !is_wp_error($quote) ? (float)($quote['05. price'] ?? 0) : 0;
        $market_cap = (float)($overview['MarketCapitalization'] ?? 0);
        $shares_outstanding = (float)($overview['SharesOutstanding'] ?? 0);
        $insider_percent = (float)($overview['PercentInsiders'] ?? 0);
        $institution_percent = (float)($overview['PercentInstitutions'] ?? 0);
        $dividend_date = $overview['DividendDate'] ?? 'N/A';
    
        $output = '<div class="jtw-content-section" id="section-overview-content">';
        $output .= '<h4>' . esc_html($ticker) . ' ' . esc_html__('Company Overview', 'journey-to-wealth') . '</h4>';
        
        $output .= '<div class="jtw-overview-main-col">';
        $output .= '<div class="jtw-company-description"><p>' . esc_html($description) . '</p></div>';
        
        $output .= '<div class="jtw-stats-container">';
        $output .= $this->create_metric_card('Current Price', $stock_price, '$');
        $output .= $this->create_metric_card('Market Capitalization', $market_cap, '$', '', true);
        $output .= $this->create_metric_card('Shares Outstanding', $shares_outstanding, '', '', true);
        $output .= $this->create_metric_card('Insider Ownership', $insider_percent, '%');
        $output .= $this->create_metric_card('Institution Ownership', $institution_percent, '%');
        $output .= $this->create_metric_card('Ex-Dividend Date', $dividend_date);
        $output .= '</div>';

        $output .= '<div class="jtw-company-details">';
        $output .= '<h4>' . esc_html__('Company Details', 'journey-to-wealth') . '</h4>';
        $output .= '<div class="jtw-details-grid">';
        $output .= '<div><strong>Exchange</strong><span>' . esc_html($overview['Exchange'] ?? 'N/A') . '</span></div>';
        $output .= '<div><strong>Sector</strong><span>' . esc_html($overview['Sector'] ?? 'N/A') . '</span></div>';
        if (!empty($overview['CIK'])) {
            $output .= '<div><strong>SEC Filings</strong><span><a href="https://www.sec.gov/edgar/browse/?CIK=' . esc_attr($overview['CIK']) . '" target="_blank" rel="noopener noreferrer">View Filings</a></span></div>';
        }
        $output .= '<div><strong>Official Site</strong><span><a href="' . esc_url($overview['OfficialSite'] ?? '#') . '" target="_blank" rel="noopener noreferrer">' . esc_html($overview['OfficialSite'] ?? 'N/A') . '</a></span></div>';
        $output .= '<div><strong>Industry</strong><span>' . esc_html($overview['Industry'] ?? 'N/A') . '</span></div>';
        $output .= '<div><strong>Fiscal Year End</strong><span>' . esc_html($overview['FiscalYearEnd'] ?? 'N/A') . '</span></div>';
        $output .= '<div><strong>52 Week High</strong><span>$' . esc_html($overview['52WeekHigh'] ?? 'N/A') . '</span></div>';
        $output .= '<div><strong>52 Week Low</strong><span>$' . esc_html($overview['52WeekLow'] ?? 'N/A') . '</span></div>';
        $output .= '<div><strong>Address</strong><span>' . esc_html($overview['Address'] ?? 'N/A') . '</span></div>';

        $output .= '</div></div>';
        $output .= '</div>';
    
        $output .= '</div>';
        return $output;
    }
    
    private function build_key_metrics_ratios_section_html($key_metrics_data, $peg_pegy_data, $stock_price, $trailing_eps, $forward_eps, $overview) {
        $output = '<div id="section-key-metrics-ratios-content" class="jtw-content-section">';
        $output .= '<h4>' . esc_html__('Key Metrics & Ratios', 'journey-to-wealth') . '</h4>';
        
        $output .= '<div class="jtw-key-metrics-wrapper">';
        
        // Left side: Grid of metric boxes
        $output .= '<div class="jtw-metrics-grid">';
        
        $market_cap = (float)($overview['MarketCapitalization'] ?? 0);
        $trailing_earnings = (is_numeric($key_metrics_data['trailingPeRatio']) && $key_metrics_data['trailingPeRatio'] > 0) ? $market_cap / $key_metrics_data['trailingPeRatio'] : 0;
        $forward_earnings = (is_numeric($key_metrics_data['forwardPeRatio']) && $key_metrics_data['forwardPeRatio'] > 0) ? $market_cap / $key_metrics_data['forwardPeRatio'] : 0;
        
        $enterprise_value = 0;
        $ev_to_revenue = $key_metrics_data['evToRevenue'];
        $revenue_ttm = (isset($overview['RevenueTTM']) && is_numeric($overview['RevenueTTM'])) ? (float)$overview['RevenueTTM'] : 0;
        $ev_to_ebitda = $key_metrics_data['evToEbitda'];
        $ebitda = (isset($overview['EBITDA']) && is_numeric($overview['EBITDA'])) ? (float)$overview['EBITDA'] : 0;

        if (is_numeric($ev_to_revenue) && $revenue_ttm > 0) {
            $enterprise_value = $ev_to_revenue * $revenue_ttm;
        } elseif (is_numeric($ev_to_ebitda) && $ebitda > 0) {
            $enterprise_value = $ev_to_ebitda * $ebitda;
        }

        // P/E Ratio Box
        $output .= $this->create_interactive_metric_card('P/E Ratio', $key_metrics_data['trailingPeRatio'], [
            'metric' => 'pe',
            'interactive-type' => 'donut',
            'numerator-label' => 'Earnings',
            'denominator-label' => 'Market Cap',
            'denominator-value' => $market_cap,
            'trailing-value' => $key_metrics_data['trailingPeRatio'],
            'forward-value' => $key_metrics_data['forwardPeRatio'],
            'trailing-numerator-value' => $trailing_earnings,
            'forward-numerator-value' => $forward_earnings,
        ]);
        
        // PEG/PEGY Box
        $peg_display = is_numeric($peg_pegy_data['trailing_peg']) ? number_format($peg_pegy_data['trailing_peg'], 1) . 'x' : 'N/A';
        $pegy_display = is_numeric($peg_pegy_data['trailing_pegy']) ? number_format($peg_pegy_data['trailing_pegy'], 1) . 'x' : 'N/A';
        $output .= '<div class="jtw-metric-card is-interactive" data-metric="peg-pegy" data-interactive-type="calculator" 
            data-trailing-peg="' . esc_attr($peg_pegy_data['trailing_peg']) . '" data-trailing-pegy="' . esc_attr($peg_pegy_data['trailing_pegy']) . '" 
            data-forward-peg="' . esc_attr($peg_pegy_data['forward_peg']) . '" data-forward-pegy="' . esc_attr($peg_pegy_data['forward_pegy']) . '">
            <h3 class="jtw-metric-title">PEG / PEGY Ratios</h3>
            <p class="jtw-metric-value">' . $peg_display . ' / ' . $pegy_display . '</p>
        </div>';

        // P/S Ratio Box
        $output .= $this->create_interactive_metric_card('P/S Ratio', $key_metrics_data['psRatio'], [
            'interactive-type' => 'donut',
            'numerator-label' => 'Sales',
            'numerator-value' => $revenue_ttm,
            'denominator-label' => 'Market Cap',
            'denominator-value' => $market_cap,
        ]);

        // P/B Ratio Box
        $output .= $this->create_interactive_metric_card('P/B Ratio', $key_metrics_data['pbRatio'], [
            'interactive-type' => 'donut',
            'numerator-label' => 'Book',
            'numerator-value' => (is_numeric($key_metrics_data['pbRatio']) && $key_metrics_data['pbRatio'] > 0) ? $market_cap / $key_metrics_data['pbRatio'] : 0,
            'denominator-label' => 'Market Cap',
            'denominator-value' => $market_cap,
        ]);
        
        // EV/Revenue Box
        $output .= $this->create_interactive_metric_card('EV/Revenue', $key_metrics_data['evToRevenue'], [
            'interactive-type' => 'donut',
            'numerator-label' => 'Revenue',
            'numerator-value' => $revenue_ttm,
            'denominator-label' => 'Enterprise Value',
            'denominator-value' => $enterprise_value,
        ]);

        // EV/EBITDA Box
        $output .= $this->create_interactive_metric_card('EV/EBITDA', $key_metrics_data['evToEbitda'], [
            'interactive-type' => 'donut',
            'numerator-label' => 'EBITDA',
            'numerator-value' => $ebitda,
            'denominator-label' => 'Enterprise Value',
            'denominator-value' => $enterprise_value,
        ]);
        
        $output .= '</div>'; // End jtw-metrics-grid
        
        // Right side: Interactive Elements
        $output .= '<div class="jtw-interactive-element-container">';
        $output .= '<div class="jtw-interactive-donut-container">';
        $output .= '<canvas id="jtw-key-metrics-donut-chart"></canvas>';
        $output .= '<div class="jtw-donut-top-text"></div>';
        $output .= '<div class="jtw-donut-center-text"></div>';
        $output .= '</div>';

        $output .= '<div class="jtw-peg-pegy-calculator-container" style="display: none;">';
        if (!is_numeric($trailing_eps) || $trailing_eps <= 0) {
            $output .= '<div class="jtw-metric-card"><p><strong>' . esc_html__('The company is not profitable yet.', 'journey-to-wealth') . '</strong></p></div>';
        } else {
            $growth_default = number_format((float)($peg_pegy_data['defaultGrowth'] ?? 5), 2, '.', '');
            $dividend_yield_default = number_format((float)($peg_pegy_data['dividendYield'] ?? 0), 2, '.', '');
            
            $output .= '<div class="jtw-metric-card jtw-interactive-card"><div class="jtw-peg-pegy-calculator"><div class="jtw-peg-pegy-inputs-grid">';
            $output .= '<div class="jtw-form-group"><label for="jtw-sim-stock-price">Stock Price ($):</label><input type="number" step="0.01" id="jtw-sim-stock-price" class="jtw-sim-input" value="' . esc_attr($stock_price) . '"></div>';
            $output .= '<div class="jtw-form-group"><label for="jtw-sim-eps">Earnings per Share ($):</label><input type="number" step="0.01" id="jtw-sim-eps" class="jtw-sim-input" value="' . esc_attr($trailing_eps) . '" data-trailing-eps="' . esc_attr($trailing_eps) . '" data-forward-eps="' . esc_attr($forward_eps) . '"></div>';
            $output .= '<div class="jtw-form-group"><label for="jtw-sim-growth-rate">Estimated Annual Earnings Growth (%):</label><input type="number" step="0.1" id="jtw-sim-growth-rate" class="jtw-sim-input" value="' . esc_attr($growth_default) . '"></div>';
            $output .= '<div class="jtw-form-group"><label for="jtw-sim-dividend-yield">Estimated Annual Dividend Yield (%):</label><input type="number" step="0.01" id="jtw-sim-dividend-yield" class="jtw-sim-input" value="' . esc_attr($dividend_yield_default) . '"></div>';
            $output .= '</div><div class="jtw-peg-pegy-results">';
            $output .= '<div class="jtw-bar-result"><span class="jtw-result-label">PEG Ratio</span><div class="jtw-bar-container"><div id="jtw-peg-bar" class="jtw-bar"><span id="jtw-peg-value" class="jtw-bar-value">-</span></div></div></div>';
            $output .= '<div class="jtw-bar-result"><span class="jtw-result-label">PEGY Ratio</span><div class="jtw-bar-container"><div id="jtw-pegy-bar" class="jtw-bar"><span id="jtw-pegy-value" class="jtw-bar-value">-</span></div></div></div>';
            $output .= '</div></div></div>';
        }
        $output .= '</div>'; // End jtw-peg-pegy-calculator-container
        
        // P/E Toggle Switch
        if (is_numeric($key_metrics_data['forwardPeRatio'])) {
            $output .= '<div class="jtw-pe-toggle-switch">';
            $output .= '<span class="jtw-toggle-label">Trailing P/E</span>';
            $output .= '<label class="jtw-switch"><input type="checkbox" id="jtw-pe-toggle"><span class="slider round"></span></label>';
            $output .= '<span class="jtw-toggle-label">Forward P/E</span>';
            $output .= '</div>';
        }
        
        $output .= '</div>'; // End jtw-interactive-element-container
        $output .= '</div>'; // End jtw-key-metrics-wrapper
        
        $output .= '</div>';
        return $output;
    }

    private function build_past_performance_section_html($historical_data) {
        $unique_id = 'hist-trends-' . uniqid();
        $output = '<div id="section-past-performance-content" class="jtw-content-section">';
        $output .= '<h4>' . esc_html__('Visual Trends', 'journey-to-wealth') . '</h4>';
        $output .= '<div class="jtw-chart-controls"><div class="jtw-period-toggle">';
        $output .= '<button class="jtw-period-button active" data-period="annual">Annual</button><button class="jtw-period-button" data-period="quarterly">Quarterly</button></div>';
        $output .= '<div class="jtw-chart-filter-toggle"><button class="jtw-category-button active" data-category="all">All Charts</button><button class="jtw-category-button" data-category="growth">Growth</button><button class="jtw-category-button" data-category="profitability">Profitability</button><button class="jtw-category-button" data-category="financial_health">Financial Health</button><button class="jtw-category-button" data-category="dividends_capital">Dividends & Capital</button></div></div>';
        $output .= '<div class="jtw-historical-charts-grid" id="' . esc_attr($unique_id) . '">';
        $chart_configs = [
            'revenue' => ['title' => 'Revenue', 'type' => 'bar', 'prefix' => '$', 'category' => 'growth', 'colors' => ['#ffc107']],
            'net_income' => ['title' => 'Net Income', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#fd7e14']],
            'ebitda' => ['title' => 'EBITDA', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#82ca9d']],
            'fcf' => ['title' => 'Free Cash Flow', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#20c997']],
            'cash_and_debt' => ['title' => 'Cash & Debt', 'type' => 'bar', 'prefix' => '$', 'category' => 'financial_health', 'colors' => ['#dc3545', '#28a745']],
            'expenses' => ['title' => 'Expenses', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#007bff', '#fd7e14', '#6c757d']],
            'dividend' => ['title' => 'Dividend Per Share', 'type' => 'bar', 'prefix' => '$', 'category' => 'dividends_capital', 'colors' => ['#6f42c1']],
            'shares_outstanding' => ['title' => 'Shares Outstanding', 'type' => 'bar', 'prefix' => '', 'category' => 'dividends_capital', 'colors' => ['#17a2b8']],
            'eps' => ['title' => 'EPS', 'type' => 'bar', 'prefix' => '$', 'category' => 'profitability', 'colors' => ['#ffc107']],
        ];
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

    private function build_intrinsic_valuation_section_html($valuation_data, $valuation_summary, $details) {
        $output = '<div id="section-intrinsic-valuation-content" class="jtw-content-section">';
    
        // Create a header with the title and the "View Assumptions" button
        $output .= '<div class="jtw-section-header">';
        $output .= '<h4>' . esc_html__('Fair Value Analysis', 'journey-to-wealth') . '</h4>';
    
        // Check if there are any valid models to determine if the button should be shown
        $has_valid_models = false;
        foreach ($valuation_data as $result) {
            if (!is_wp_error($result)) {
                $has_valid_models = true;
                break;
            }
        }
    
        if ($has_valid_models) {
            $output .= '<button class="jtw-modal-trigger" data-modal-target="#jtw-assumptions-modal">' . esc_html__('View Assumptions', 'journey-to-wealth') . '</button>';
        }
        $output .= '</div>'; // End jtw-section-header
    
        // Display the valuation chart
        if ($valuation_summary['fair_value'] > 0) {
            $output .= '<div class="jtw-fair-value-container">';
            $output .= '<div id="jtw-valuation-chart-container" class="jtw-valuation-chart-container" ';
            $output .= 'data-current-price="' . esc_attr($valuation_summary['current_price']) . '" ';
            $output .= 'data-fair-value="' . esc_attr($valuation_summary['fair_value']) . '" ';
            $output .= 'data-percentage-diff="' . esc_attr($valuation_summary['percentage_diff']) . '">';
            $output .= '<canvas id="jtw-valuation-chart"></canvas>';
            $output .= '</div>';
            $output .= '</div>'; // End jtw-fair-value-container
        } else {
            $output .= '<div class="jtw-metric-card"><p><strong>' . esc_html__('Not enough data to calculate an average fair value.', 'journey-to-wealth') . '</strong></p></div>';
        }
    
        // Create a single modal for all model assumptions
        if ($has_valid_models) {
            $modal_id = 'jtw-assumptions-modal';
            $output .= '<div id="' . $modal_id . '" class="jtw-modal"><div class="jtw-modal-content"><span class="jtw-modal-close">&times;</span>';
            
            // Loop through and build content for the single modal
            foreach ($valuation_data as $model_name => $result) {
                if (is_wp_error($result)) {
                    $output .= '<h4>' . esc_html($model_name) . '</h4>';
                    $output .= '<div class="jtw-metric-card"><p><strong>' . esc_html__('Error:', 'journey-to-wealth') . '</strong> ' . esc_html($result->get_error_message()) . '</p></div>';
                } else {
                    $output .= $this->build_valuation_assumptions_modal_html($result, $details);
                }
            }
    
            $output .= '</div></div>';
        }
    
        // Add the overlay for the modal functionality
        $output .= '<div class="jtw-modal-overlay"></div>';
        $output .= '</div>'; // End #section-intrinsic-valuation-content
        return $output;
    }

    private function build_valuation_assumptions_modal_html($result, $details) {
        $data = $result['calculation_breakdown'];
        $ticker = $details['Symbol'] ?? 'the company';
        $model_name = $data['model_name'] ?? 'Valuation';
        $output = '<h4>' . esc_html($model_name) . ' Assumptions for ' . esc_html($ticker) . '</h4>';
        
        switch($model_name) {
            case 'DCF Model (FCFE)':
                $output .= $this->build_dcf_modal_content($result, $details);
                break;
            case 'Dividend Discount Model':
                $output .= $this->build_ddm_modal_content($result, $details);
                break;
            case 'AFFO Model':
                $output .= $this->build_affo_modal_content($result, $details);
                break;
            case 'Excess Return Model':
                $output .= $this->build_excess_return_modal_content($result, $details);
                break;
            default:
                $output .= '<pre>' . print_r($data, true) . '</pre>';
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
        
        $output = '<h4>Valuation</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Source</th><th>Value</th></tr></thead><tbody>';
        $output .= '<tr><td>Valuation Model</td><td></td><td>2 Stage FCFE</td></tr>';
        $output .= '<tr><td>Initial Growth Rate</td><td>' . esc_html($data['inputs']['growth_rate_source']) . '</td><td>' . number_format($data['inputs']['initial_growth_rate'] * 100, 2) . '%</td></tr>';
        if (strpos($data['inputs']['base_cash_flow_source'], 'Operating Cash Flow') !== false) {
            $output .= '<tr><td>Base Cash Flow</td><td colspan="2">' . esc_html($data['inputs']['base_cash_flow_source']) . '</td></tr>';
        }
        $output .= '<tr><td>Discount Rate</td><td>See below</td><td>' . number_format($data['inputs']['discount_rate'] * 100, 2) . '%</td></tr>';
        $output .= '<tr><td>Perpetual Growth Rate</td><td>' . esc_html($discount_calc['risk_free_rate_source']) . '</td><td>' . number_format($data['inputs']['terminal_growth_rate'] * 100, 2) . '%</td></tr>';
        $output .= '</tbody></table>';

        // Table 2: Discount Rate Calculation
        $output .= '<h4>Discount Rate</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Calculation/ Source</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Risk-Free Rate</td><td>' . esc_html($discount_calc['risk_free_rate_source']) . '</td><td>' . number_format($discount_calc['risk_free_rate'] * 100, 2) . '%</td></tr>';
        $output .= '<tr><td>Equity Risk Premium</td><td>' . esc_html($discount_calc['erp_source']) . '</td><td>' . number_format($discount_calc['equity_risk_premium'] * 100, 2) . '%</td></tr>';
        $output .= '<tr><td><strong>Discount Rate/ Cost of Equity</strong></td><td><strong>' . esc_html($discount_calc['cost_of_equity_calc']) . '</strong></td><td><strong>' . number_format($data['inputs']['discount_rate'] * 100, 2) . '%</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table for Levered Beta Calculation
        if (isset($beta_details['unlevered_beta_avg'])) {
            $output .= '<h4>Levered Beta Calculation</h4>';
            $output .= '<table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Calculation/ Source</th><th>Result</th></tr></thead><tbody>';
            $output .= '<tr><td>Unlevered Beta</td><td>Damodaran Industry Average</td><td>' . number_format($beta_details['unlevered_beta_avg'], 3) . '</td></tr>';
            if (isset($beta_details['relevered_beta_calc'])) {
                 $output .= '<tr><td>Re-levered Beta</td><td>' . esc_html($beta_details['relevered_beta_calc']) . '</td><td>' . number_format($beta_details['unconstrained_levered_beta'], 3) . '</td></tr>';
            }
            $output .= '<tr><td>Levered Beta</td><td>' . esc_html($beta_details['beta_source']) . '</td><td>' . number_format($discount_calc['beta'], 3) . '</td></tr>';
            $output .= '</tbody></table>';
        }
        
        // Table 3: FCFE Projections
        $output .= '<h4>FCFE Forecast</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>FCFE (USD)</th><th>Present Value Discounted (@' . number_format($data['inputs']['discount_rate'] * 100, 2) . '%)</th></tr></thead><tbody>';
        foreach ($data['projection_table'] as $row) {
            $output .= '<tr>';
            $output .= '<td>' . esc_html($row['year']) . '</td>';
            $output .= '<td>' . $this->format_large_number($row['cf'], '$') . '</td>';
            $output .= '<td>' . $this->format_large_number($row['pv_cf'], '$') . '</td>';
            $output .= '</tr>';
        }
        $output .= '<tr><td colspan="2"><strong>Present value of next 10 years cash flows</strong></td><td><strong>' . $this->format_large_number($data['sum_of_pv_cfs'], '$') . '</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table 4 & 5: Final Valuation
        $output .= '<h4>Final Valuation</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Terminal Value</td><td>FCFE<sub>' . end($data['projection_table'])['year'] . '</sub> &times; (1 + g) &divide; (Discount Rate - g)<br>= ' . $this->format_large_number(end($data['projection_table'])['cf'], '$') . ' &times; (1 + ' . number_format($data['inputs']['terminal_growth_rate'] * 100, 2) . '%) &divide; (' . number_format($data['inputs']['discount_rate'] * 100, 2) . '% - ' . number_format($data['inputs']['terminal_growth_rate'] * 100, 2) . '%)</td><td>' . $this->format_large_number($data['terminal_value'], '$') . '</td></tr>';
        $output .= '<tr><td>Present Value of Terminal Value</td><td>Terminal Value &divide; (1 + r)<sup>10</sup><br>' . $this->format_large_number($data['terminal_value'], '$') . ' &divide; (1 + ' . number_format($data['inputs']['discount_rate'] * 100, 2) . '%)<sup>10</sup></td><td>' . $this->format_large_number($data['pv_of_terminal_value'], '$') . '</td></tr>';
        $output .= '<tr><td><strong>Total Equity Value</strong></td><td><strong>Present value of next 10 years cash flows + PV of Terminal Value</strong><br>= ' . $this->format_large_number($data['sum_of_pv_cfs'], '$') . ' + ' . $this->format_large_number($data['pv_of_terminal_value'], '$') . '</td><td><strong>' . $this->format_large_number($data['total_equity_value'], '$') . '</strong></td></tr>';
        $output .= '<tr><td><strong>Equity Value per Share (USD)</strong></td><td><strong>Total Equity Value / Shares Outstanding</strong><br>= ' . $this->format_large_number($data['total_equity_value'], '$') . ' / ' . number_format($data['shares_outstanding']) . '</td><td><strong>$' . number_format($value_per_share, 2) . '</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table 6: Discount
        $output .= '<h4>Discount to Share Price</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Value per share (USD)</td><td>From above.</td><td>$' . number_format($value_per_share, 2) . '</td></tr>';
        $output .= '<tr><td>Current discount</td><td>Discount to share price of $' . number_format($current_price, 2) . '<br>= ($' . number_format($value_per_share, 2) . ' - $' . number_format($current_price, 2) . ') / $' . number_format($current_price, 2) . '</td><td>' . number_format($discount_pct, 1) . '%</td></tr>';
        $output .= '</tbody></table>';

        return $output;
    }

    private function build_ddm_modal_content($result, $details) {
        $data = $result['calculation_breakdown'];
        $value_per_share = $result['intrinsic_value_per_share'];
        $current_price = $data['current_price'];
        $discount_pct = ($value_per_share > 0) ? (($value_per_share - $current_price) / $current_price) * 100 : 0;

        $output = '<h4>Key Inputs & Calculation</h4><table class="jtw-modal-table"><tbody>';
        $output .= '<tr><td>Annual Dividend (D0)</td><td>$' . number_format($data['d0'], 2) . '</td></tr>';
        $output .= '<tr><td>Discount Rate (Cost of Equity)</td><td>' . number_format($data['cost_of_equity'] * 100, 2) . '%</td></tr>';
        $output .= '<tr><td>Perpetual Growth Rate (g)</td><td>' . number_format($data['growth_rate'] * 100, 2) . '%</td></tr>';
        $output .= '<tr><td><strong>Calculation</strong></td><td>(D0 * (1 + g)) / (Cost of Equity - g)</td></tr>';
        $output .= '<tr><td><strong>Intrinsic Value</strong></td><td><strong>$' . number_format($value_per_share, 2) . '</strong></td></tr>';
        $output .= '</tbody></table>';

        $output .= '<h4>Discount to Share Price</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Value per share (USD)</td><td>From above.</td><td>$' . number_format($value_per_share, 2) . '</td></tr>';
        $output .= '<tr><td>Current discount</td><td>Discount to share price of $' . number_format($current_price, 2) . '<br>= ($' . number_format($value_per_share, 2) . ' - $' . number_format($current_price, 2) . ') / $' . number_format($current_price, 2) . '</td><td>' . number_format($discount_pct, 1) . '%</td></tr>';
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
        $output .= '<tr><td><strong>Discount Rate/ Cost of Equity</strong></td><td><strong>' . esc_html($discount_calc['cost_of_equity_calc']) . '</strong></td><td><strong>' . number_format($data['inputs']['cost_of_equity'] * 100, 2) . '%</strong></td></tr>';
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
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>AFFO (USD)</th><th>Source</th><th>Present Value Discounted (@' . number_format($data['inputs']['cost_of_equity'] * 100, 2) . '%)</th></tr></thead><tbody>';
        foreach ($data['projection_table'] as $row) {
            $output .= '<tr>';
            $output .= '<td>' . esc_html($row['year']) . '</td>';
            $output .= '<td>' . $this->format_large_number($row['affo'], '$') . '</td>';
            $output .= '<td>Est @ ' . number_format(($row['affo'] / ($data['projection_table'][0]['affo'] / (1 + $data['inputs']['initial_growth_rate'])) - 1) * 100, 2) . '%</td>';
            $output .= '<td>' . $this->format_large_number($row['pv_affo'], '$') . '</td>';
            $output .= '</tr>';
        }
        $output .= '<tr><td colspan="3"><strong>Present value of next 10 years cash flows</strong></td><td><strong>' . $this->format_large_number($data['sum_of_pv_affos'], '$') . '</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table 4 & 5: Final Valuation
        $output .= '<h4>Final Valuation</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Terminal Value</td><td>FCF<sub>2035</sub> &times; (1 + g) &divide; (Discount Rate - g)<br>= ' . $this->format_large_number(end($data['projection_table'])['affo'], '$') . ' &times; (1 + ' . number_format($data['inputs']['terminal_growth_rate'] * 100, 2) . '%) &divide; (' . number_format($data['inputs']['cost_of_equity'] * 100, 2) . '% - ' . number_format($data['inputs']['terminal_growth_rate'] * 100, 2) . '%)</td><td>' . $this->format_large_number($data['terminal_value'], '$') . '</td></tr>';
        $output .= '<tr><td>Present Value of Terminal Value</td><td>Terminal Value &divide; (1 + r)<sup>10</sup><br>' . $this->format_large_number($data['terminal_value'], '$') . ' &divide; (1 + ' . number_format($data['inputs']['cost_of_equity'] * 100, 2) . '%)<sup>10</sup></td><td>' . $this->format_large_number($data['pv_of_terminal_value'], '$') . '</td></tr>';
        $output .= '<tr><td><strong>Total Equity Value</strong></td><td><strong>Present value of next 10 years cash flows + PV of Terminal Value</strong><br>= ' . $this->format_large_number($data['sum_of_pv_affos'], '$') . ' + ' . $this->format_large_number($data['pv_of_terminal_value'], '$') . '</td><td><strong>' . $this->format_large_number($data['total_equity_value'], '$') . '</strong></td></tr>';
        $output .= '<tr><td><strong>Equity Value per Share (USD)</strong></td><td><strong>Total Equity Value / Shares Outstanding</strong><br>= ' . $this->format_large_number($data['total_equity_value'], '$') . ' / ' . number_format($data['shares_outstanding']) . '</td><td><strong>$' . number_format($value_per_share, 2) . '</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table 6: Discount
        $output .= '<h4>Discount to Share Price</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Value per share (USD)</td><td>From above.</td><td>$' . number_format($value_per_share, 2) . '</td></tr>';
        $output .= '<tr><td>Current discount</td><td>Discount to share price of $' . number_format($current_price, 2) . '<br>= ($' . number_format($value_per_share, 2) . ' - $' . number_format($current_price, 2) . ') / $' . number_format($current_price, 2) . '</td><td>' . number_format($discount_pct, 1) . '%</td></tr>';
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
        $output .= '<tr><td>Book Value of Equity per Share</td><td>Latest Annual Report</td><td>$' . number_format($data['current_book_value_per_share'], 2) . '</td></tr>';
        $output .= '<tr><td>Discount Rate (Cost of Equity)</td><td>See below</td><td>' . number_format($data['cost_of_equity'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td>Perpetual Growth Rate</td><td>' . esc_html($discount_calc['risk_free_rate_source']) . '</td><td>' . number_format($data['terminal_growth_rate'] * 100, 1) . '%</td></tr>';
        $output .= '</tbody></table>';

        // Table 2: Discount Rate Calculation
        $output .= '<h4>Discount Rate</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th>Data Point</th><th>Calculation/ Source</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Risk-Free Rate</td><td>' . esc_html($discount_calc['risk_free_rate_source']) . '</td><td>' . number_format($discount_calc['risk_free_rate'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td>Equity Risk Premium</td><td>' . esc_html($discount_calc['erp_source']) . '</td><td>' . number_format($discount_calc['equity_risk_premium'] * 100, 1) . '%</td></tr>';
        $output .= '<tr><td><strong>Discount Rate/ Cost of Equity</strong></td><td><strong>' . esc_html($discount_calc['cost_of_equity_calc']) . '</strong></td><td><strong>' . number_format($data['inputs']['cost_of_equity'] * 100, 2) . '%</strong></td></tr>';
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
        $output .= '<tr><td>Excess Returns</td><td>(Return on Equity - Cost of Equity) x (Book Value of Equity per share)<br>= (' . number_format($data['roe'] * 100, 1) . '% - ' . number_format($data['cost_of_equity'] * 100, 2) . '%) x $' . number_format($data['current_book_value_per_share'], 2) . '</td><td>$' . number_format($data['excess_return_per_share'], 2) . '</td></tr>';
        $output .= '<tr><td>Terminal Value of Excess Returns</td><td>Excess Returns / (Cost of Equity - Expected Growth Rate)<br>= $' . number_format($data['excess_return_per_share'], 2) . ' / (' . number_format($data['cost_of_equity'] * 100, 2) . '% - ' . number_format($data['terminal_growth_rate'] * 100, 2) . '%)</td><td>$' . number_format($data['terminal_value_of_excess_returns_per_share'], 2) . '</td></tr>';
        $output .= '<tr><td><strong>Value of Equity</strong></td><td><strong>Book Value per share + Terminal Value of Excess Returns</strong><br><strong>$' . number_format($data['current_book_value_per_share'], 2) . ' + $' . number_format($data['terminal_value_of_excess_returns_per_share'], 2) . '</strong></td><td><strong>$' . number_format($value_per_share, 2) . '</strong></td></tr>';
        $output .= '</tbody></table>';

        // Table 4: Discount to Share Price
        $output .= '<h4>Discount to Share Price</h4>';
        $output .= '<table class="jtw-modal-table"><thead><tr><th></th><th>Calculation</th><th>Result</th></tr></thead><tbody>';
        $output .= '<tr><td>Value per share (USD)</td><td>From above.</td><td>$' . number_format($value_per_share, 2) . '</td></tr>';
        $output .= '<tr><td>Current discount</td><td>Discount to share price of $' . number_format($current_price, 2) . '<br>= ($' . number_format($value_per_share, 2) . ' - $' . number_format($current_price, 2) . ') / $' . number_format($current_price, 2) . '</td><td>' . number_format($discount_pct, 1) . '%</td></tr>';
        $output .= '</tbody></table>';

        return $output;
    }

    private function create_metric_card($title, $value, $prefix = '', $custom_class = '', $use_large_number_format = false) {
        $formatted_value = 'N/A';
        if (is_numeric($value)) {
            if ($use_large_number_format) {
                $final_prefix = ($title === 'Shares Outstanding') ? '' : $prefix;
                $formatted_value = $this->format_large_number($value, $final_prefix, 2);
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
                            $formatted_value = $this->format_large_number($value, '', 2);
                            break;
                        case 'shareholder_equity':
                            $formatted_value = ($value != 0) ? $this->format_large_number($value, '$') : 'N/A';
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
