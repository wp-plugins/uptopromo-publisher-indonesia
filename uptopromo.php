<?php
/*
Plugin Name: Uptopromo Publisher Indonesia
Description: Plugins uptopromo untuk publisher di Indonesia membantu menginstall PHP kode dari slot iklan di dalam website wordpress hanya dengan 3 klik saja - dan dapatkan pendapatan secara otomatis dengan http://www.uptopromo.com
Author: ipnino.ru
Plugin URI: http://www.ipnino.ru/portfolio/razrabotka-plaginov-modulej-komponentov/wp-plugin-uptopromo-publisher-indonesia/
Author URI: http://www.ipnino.ru/
Version: 0.1
*/ 


/**
* Base settings
**/
define('PR_API_URL', 'http://uptopromo.com/api/');

define('PR_PLUGIN_PATH', dirname(__FILE__));
define('PR_PLUGIN_URL', plugins_url('', __FILE__));

// path to client
define('PR_CLIENT_FILE', 'promo.php');

define('PR_DEBUG', FALSE);

class PromoCore
{
	private $errors = array();

	public function __construct()
	{
		// register hooks for plugin activation/deactivation
		register_activation_hook(__FILE__, array(&$this, 'activate'));
		register_deactivation_hook(__FILE__, array(&$this, 'deactivate'));

		// run only when widgets init
		add_action('widgets_init', array(&$this, 'widgets_init'));

		add_action('init', array(&$this, 'init'), 1);
		add_action('init', array(&$this, 'deactivate_cache_plugins'));

		add_action('admin_menu', array(&$this, 'admin_menu'));
		
		add_action( 'wp_enqueue_scripts', array(&$this, 'frontend_scripts') );
	}

	public function activate()
	{
		add_option('promo_userid', 0);
		add_option('promo_email', '');
		add_option('promo_password', '');
		add_option('promo_platformid', '');
		add_option('promo_userhash', '');
		add_option('promo_token', '');
	}

	public function deactivate()
	{
		delete_option('promo_userid', 0);
		delete_option('promo_email', '');
		delete_option('promo_password', '');
		delete_option('promo_platformid', '');
		delete_option('promo_userhash', '');
		delete_option('promo_token', '');
	}

	public function init()
	{
		session_start();
	}
	
	public function deactivate_cache_plugins() {
		$plugins = get_option('active_plugins');
		require_once(ABSPATH . 'wp-admin/includes/plugin.php');
		if(!empty($plugins)){
			foreach($plugins as $value){
				if(strstr($value, 'cache')){
					deactivate_plugins(WP_PLUGIN_DIR .'/'. $value);
				}
			}
		}
		
	}
	
	public function frontend_scripts() {
	
		wp_enqueue_script( 'utp', plugin_dir_url( __FILE__ ) . 'js/utp.js', array('jquery') );
	
	}

	public function widgets_init()
	{
		// create WP shortcode
		add_action('wp_footer', array(&$this, 'shortcode'));
	}

	public function shortcode()
	{
		$hash = get_option('promo_userhash');

		$platform_id = get_option('promo_platformid');

		if ( ! $platform_id)
		{
			return;
		}

		$token = get_option('promo_token');

		if ( ! $token)
		{
			return;
		}

		if ( ! $hash)
		{
			$res = $this->api_request('platforms/'.$platform_id.'/code_test_url.json', array('token' => $token));
			if ( ! $this->errors && $res['status'] == 1)
			{
				$test_code_url = $res['test_code_url'];
				$temp_arr = explode('promo_test=', $test_code_url);

				$hash = $temp_arr[1];

				if ( ! $hash)
				{
					update_option('promo_userhash', '');
					update_option('promo_platformid', 0);
					return;
				}
				else
				{
					update_option('promo_userhash', $hash);
				}
			}
			else
			{
				update_option('promo_userhash', '');
				update_option('promo_platformid', 0);
				return;
			}
		}

		define('PROMO_USER', $hash);


		$upload_dir = ABSPATH.'wp-content/uploads/';

		//if (file_exists(PR_PLUGIN_PATH.'/'.PR_CLIENT_FILE))
		//{
		if ( ! is_dir($upload_dir.'/'.PROMO_USER))
		{
			mkdir($upload_dir.'/'.PROMO_USER);
			chown($upload_dir.'/'.PROMO_USER, 0777);
		}

		if (is_dir($upload_dir.'/'.PROMO_USER))
		{
			copy(PR_PLUGIN_PATH.'/'.PR_CLIENT_FILE, $upload_dir.'/'.PROMO_USER.'/'.PR_CLIENT_FILE);
			//unlink(PR_PLUGIN_PATH.'/'.PR_CLIENT_FILE);
		}
		//}
		else
		{
			$path = str_replace('temp_name', $hash, $path);
		}

		require_once($upload_dir.'/'.PROMO_USER.'/'.PR_CLIENT_FILE);
		$promo = new PromoClient();
		echo $promo->build_links();
	}

	public function admin_menu()
	{
		add_utility_page('UptopromoPI', 'UptopromoPI', 'manage_options', 'uptopromo/uptopromo.php', array(&$this, 'options'), '');
		add_submenu_page( 'uptopromo/uptopromo.php', 'Settings', 'Settings', 'manage_options', 'uptopromo/uptopromo.php', array(&$this, 'options') );

		if (get_option('promo_token'))
		{
			add_submenu_page( 'uptopromo/uptopromo.php', 'Payments', 'Payments', 'manage_options', 'uptopromo/uptopromo.php?payments', array(&$this, 'payments') );
		}
	}

	private function api_request($method, $params, $type = 'get')
	{
		$this->errors = array();

		$query = http_build_query($params);

	    $url =  PR_API_URL.$method.'?'.$query;

	    $headers = array(
	    	'Accept: application/json, text/javascript, */*; q=0.01',
	    	'X-Requested-With: XMLHttpRequest',
	    	'Cache-Control: max-age=0',
	    	'Content-Length: 0',
	    	'X-HTTP-Method-Override: PUT'
	    );

	    $ch = curl_init();

	    curl_setopt($ch, CURLOPT_URL, $url);

	    curl_setopt($ch, CURLOPT_VERBOSE, true);

	    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	    if (PR_DEBUG)
	    	echo '<p>URL: '.$url.'</p>';

	    if ($type == 'put')
	    {
	    	curl_setopt($ch, CURLOPT_POSTFIELDS, $query);

	    	if (PR_DEBUG)
	    		echo '<p>PUT</p>';
	    }


	    //curl_setopt($ch, CURLOPT_NOBODY, TRUE);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

	    $response = curl_exec($ch);
	    curl_close($ch);

	    if (PR_DEBUG)
	    	var_export($response);

	    $response_arr = json_decode($response, TRUE);

	    if (PR_DEBUG)
	    	var_export($response_arr);

	    if ($response_arr === NULL)
	    {
	    	$this->errors[] = 'API error';
	    }
	    else if ( ! is_array($response_arr))
	    {
	    	return $response;
	    }


	    return $response_arr;
	}

	public function options()
	{
		$themes = array();
		$url_data = parse_url(get_option('siteurl'));
		$domain = $url_data['host'];

		$userid = get_option('promo_userid');

		if ( ! get_option('promo_token'))
		{
			$this->login_form();
			return;
		}

		$params_token = array('token' => get_option('promo_token'));

		if ($_POST)
		{
			$domain = $_POST['domain'];
			$themes = $_POST['themes'];

			$params = array_merge($params_token, array());
			$params['domain'] = $domain;
			$params['themes'] = implode(',', $themes);

			$res = $this->api_request('platforms/create.json', $params, 'put');
			//print_r($res); die();
			$this->merge_response_errors($res);
						
			if($themes){
				$theme_error = $this->errors[0];
			}
			
			if ( ! $this->errors && $res['status'])
			{
				update_option('promo_platformid', $res['platform_id']);
				$params = array_merge($params_token, array());
				$res = $this->api_request('platforms/'.$res['platform_id'].'/force_check_code.json', $params);
			}
		}

		$res = $this->api_request('money/balance.json', $params_token);
		//$this->merge_response_errors($res);
		if ( ! $this->errors)
		{
			$balance = round(trim($res, '"'));
		}

		$res = $this->api_request('money/incomes.json', array_merge($params_token,
			array('timestamp_start' => time()-24*60*60*30, 'timestamp_end' => time())));
		//$this->merge_response_errors($res);
		if (is_array($res) && isset($res[0]))
		{
			$incomes = $res;
		}

		?>
		<div class="wrap">
		<?php if(get_option('promo_platformid')){ ?>
		    <h2>Akun Statistik Finansial Uptopromo Anda</h2>
		    <?php $this->print_errors();?>
		
				<h4>Saldo: <?php echo $balance;?> Rp.</h4>
				<h4>Pendapatan Bulanan:</h4>
				<?php if (isset($incomes) && sizeof($incomes) > 0):?>
				<table>
					<tr>
						<td>Jumlah</td>
						<td>Tanggal</td>
						<td>Tipe</td>
					</tr>
				<?php
				foreach ($incomes as $inc)
				{
					?>
					<tr>
					<td><?php echo $inc['amount'];?></td>
					<td><?php echo $inc['billing_for_date'];?></td>
					<td><?php echo $inc['target_type'];?></td>
					</tr>
					<?php
				}
				?>
				</table>
			<?php else: ?>
			data belum tersedia
		<?php endif; ?>
		
		<?php } else { ?>	
			
		    <?php
		    if ( ! get_option('promo_platformid')):
		    	$res = $this->api_request('platforms/themes/list.json', $params_token);
		   		//$this->merge_response_errors($res);
				if ( ! $this->errors)
				{
					$themes_arr = $res;
				}
			?>
				
			<h2>Menambahkan situs pada sistem untuk memulai memonetasi</h2>
			<p>Pada halaman ini Anda harus memilih tema dari situs Anda dan menambahkan nya pada sistem Uptopromo untuk memulai mendapatkan penghasilan. Harap diperhatikan, bahwa platform tidak bisa ditambahkan pada akun Anda! Apabila Anda sudah menambahkan nya, silahkan membuang situs dari <a href="http://uptopromo.com/id/platforms">daftar platform</a> dan menambah kembali pada halaman ini dengan plugin!
			Setelah memasukan, silahkan mengecek email Anda mengenai platform untuk menyetujui atau melakukan penawaran pendapatan untuk situs Anda. Apabila ada pertanyaan mengenai hal ini, silahkan menghubungi kami di: support@uptopromo.com atau melalui layanan Telpon/sms/whatsapp: 081291993399</p>
		    <form method="post" action="">
		    	<?php $this->print_errors()?>
				<p style="color: red;"><?php echo $theme_error; ?></p>	
		    	<p><label>Domain</label> <input type="text" name="domain" readonly value="<?php echo $domain?>"></p>
		    	<p><label>Tema</label> <select multiple name="themes[]">
		    	<?php foreach ($themes_arr as $t):?>
		    		<option <?php if (in_array($t['id'], $themes)) echo 'selected';?> value="<?php echo $t['id']?>"><?php echo $t['name'];?></option>
		    	<?php endforeach; ?>
		    	</select></p>
		    	<p class="submit">
					<input type="submit" name="update" class="button-primary" value="Submit">
				</p>
			</form>
		 	<?php endif; ?>
			<?php } ?>	
			<a style="float: right;" href="http://uptopromo.com/id/platforms"><img src="<?php echo plugins_url('logo.jpg', __FILE__); ?>" /></a>
		</div>
		<?php
	}

	private function merge_response_errors($response)
	{
		if (isset($response['error']))
		{
			$this->errors[] = $response['error'];
		}

		if (isset($response['errors']) && is_array($response['errors']))
		{
			foreach ($response['errors'] as $e)
			{
				$this->errors[] = $e;
			}
		}
	}

	private function auth()
	{
		$email = get_option('promo_email');
		$password = get_option('promo_password');

		$res = $this->api_request('auth/login.json', array('email' => $email, 'password' => $password), 'put');

		if ( ! $this->errors && $res['status'])
		{
			update_option('promo_token', $res['token']);
			update_option('promo_userid', $res['user_id']);
			return TRUE;
		}
		else
		{
			$this->merge_response_errors($res);
			return FALSE;
		}
	}

	public function print_errors()
	{
		if ($this->errors)
		{
	    	foreach ($this->errors as $e)
	    	{
	    		?><p style="color:red"><?php echo $e?></p><?php
	    	}
	    }
	}
	
	public function login_form()
	{
		$email = get_option('promo_email');

		if ($_POST)
		{
			$email = $_POST['email'];
			$password = $_POST['password'];
			update_option('promo_email', $email);
			update_option('promo_password', $password);
			if ($this->auth())
			{
				$success = true;
			}
		}

		?>
		<div class="wrap">
			<h2>Login menggunakan akun Uptopromo Anda</h2>
			<p>Silahkan melakukan Login dengan menggunakan akun Uptopromo Anda, dan klik tombol "Login".
			Apabila anda belum mempunyai akun di Uptopromo, silahkan mengklik tombol "Buat Akun", melengkapi pendaftaran, dan kemudian silahkan kembali ke halaman ini</p>
		    <?php if (isset($success)):?>
			<p>Login berhasil</p>
			<p><a href="<?php echo admin_url('admin.php?page=uptopromo/uptopromo.php');?>">Menuju ke pengaturan</a></p>
			<script>
			jQuery(document).ready(function(){
				window.location = '<?php echo admin_url('admin.php?page=uptopromo/uptopromo.php');?>';
			});
			</script>
			<?php $this->print_errors()?>
			<?php else:?>
		    <form method="post" action="" >
		    	<?php $this->print_errors()?>
		    	<p><label>E-mail</label> <input type="text" name="email" value="<?php echo $email?>"></p>
		    	<p><label>Password</label> <input type="password" name="password"></p>
		    	<p class="submit">
					<input type="submit" name="update" class="button-primary" value="Log In"> atau 
					 &nbsp;<a href="http://uptopromo.com/id/users/sign_up" target="_blank">Buat Akun</a>

				</p>
		    </form>
		<?php endif; ?>
		<a style="float: right;" href="http://uptopromo.com/id/platforms"><img src="<?php echo plugins_url('logo.jpg', __FILE__); ?>" /></a>
		</div>
		
		<?php		
	}

	public function payments()
	{
		$userid = get_option('promo_userid');

		if ( ! $userid)
		{
			$this->register_form();
			return;
		}

		if ( ! get_option('promo_token'))
		{
			$this->login_form();
			return;
		}

		

		$params_token = array('token' => get_option('promo_token'));

		$res = $this->api_request('money/balance.json', $params_token);
		//$this->merge_response_errors($res);
		if ( ! $this->errors)
		{
			$balance = round(trim($res, '"'));
		}

		if ($_POST)
		{
			$params = array_merge($params_token, array('amount' => floatval($_POST['amount'])));
			$res = $this->api_request('money/withdrawals/create.json', $params, 'put');
			$this->merge_response_errors($res);
			if ( ! $this->errors)
			{
				$success = TRUE;
			}
		}
		?>
		<div class="wrap">
		    <h2>Pembayaran Uptopromo</h2>
		    <?php if (isset($success)):?>
			<p>Berhasil</p>
			<?php $this->print_errors()?>
			<?php else:?>
		    <form method="post" action="" >
		    	<a style="color: red;" href="http://uptopromo.com/id/users/payment" target="_blank"><?php $this->print_errors()?></a>
		    	<p>Saldo: <?php echo $balance;?> Rp.</p>
		    	<p>Permintaan WIthdrawal: <input type="text" name="amount"></p>
		    	<p class="submit">
					<input type="submit" name="update" class="button-primary" value="Submit">
				</p>
		    </form>
		<?php endif; ?>
		<a style="float: right;" href="http://uptopromo.com/id/platforms"><img src="<?php echo plugins_url('logo.jpg', __FILE__); ?>" /></a>
		</div>
		<?php	

	}
}

// Init plugin
function PromoPluginInit()
{
	$ContentMonster = new PromoCore();
}

PromoPluginInit();

