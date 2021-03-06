<?php

  $app->post('/api/', function () use ($app) {
    error_reporting(E_ALL);
    define('IOS', strpos($app->request->getUserAgent(), 'iPhone') > 0);
    date_default_timezone_set('Europe/Kiev');

    $app->response->headers->set('Content-Type', 'application/json');
    $runtime = time();
    $request = json_decode($app->request->getBody());

    // check signature
    $unescaped = preg_replace_callback('/\\\\u(\w{4})/', function ($matches) {
        return html_entity_decode('&#x' . $matches[1] . ';', ENT_COMPAT, 'UTF-8');
    }, json_encode($request->data));

    $request = json_decode(urldecode($app->request->getBody()));

    $SIGN = md5($unescaped . API_KEY);

    if (DEBUG_MODE) { 
      file_put_contents(PATH_CACHE . 'logs' . DS . 'request.log', 
        "\n\n".date("Y-m-d H:i:s")."\n".
        'User-agent:' .$app->request->getUserAgent(). 
        "\nREQUEST\n" . 
        $app->request->getBody() . "\n".
        "SERVER SIGNATURE = ".$SIGN
      , FILE_APPEND);
    }

    $response = array();
    /**
     * get_catalog
     */
    if ($request->function == "get_catalog") {
      $file = "android";
      if (!empty($request->data->date)) {
        $dt   = (int)$request->data->date;
        $file = "android_update";
      } else $dt = 0;
      /**
       * Products
       */
      $query                = "
				SELECT
					product_id AS 'id',
					product_name AS 'name',
					product_description AS 'description',
					'none' AS 'manufacturer',
					'none' AS 'volume',
					product_price AS 'price',
					CONCAT('" . URL_ROOT . "data/products/', p.product_id, '/', p.product_cover) AS 'cover',
					product_visible AS 'visible',
					product_deleted AS 'deleted'
				FROM `products` p
				WHERE " . ($dt > 0 ? " product_updated > '" . date("Y-m-d H:i:s", $dt) . "'" : " product_visible = 1");
        // die($query);
      $response['products'] = $app->db->getAll($query);
      foreach ($response['products'] as $key => $product) {
        $values = $app->db->getAll("SELECT category_id FROM `lnk_products_categories` WHERE product_id = " . $product['id']);
        foreach ($values as $value) {
          $response['products'][$key]['category_id'][] = $value['category_id'];
        }
      }
      /**
       * Categories
       */
      $response['categories'] = $app->db->getAll("SELECT category_id AS 'id', category_name AS 'name', 0 AS 'primary_id' FROM `categories`");
      /**
       * Delivery
       */
      $response['delivery_types'] = $app->db->getAll("SELECT delivery_id AS 'id', delivery_name AS 'name', delivery_cost AS 'cost' FROM `delivery` WHERE delivery_active = 1");
      /**
       *  Filters
       */
      $response['filters'] = $app->db->getAll("SELECT filter_id AS 'id', filter_name AS 'name', filter_type AS 'type' FROM `filters`");
      foreach ($response['filters'] as $key => $value) {
        $values = $app->db->getAll("SELECT category_id FROM `lnk_filters_categories` WHERE filter_id = " . $value['id']);
        foreach ($values as $value) {
          $response['filters'][$key]['category_id'][] = $value['category_id'];
        }
      }
      /**
       * Filter values link
       */
      $response['filter_link'] = $app->db->getAll("SELECT filter_id, product_id, value_id AS 'filter_value_id' FROM `lnk_products_values`");
      /**
       * Filter values
       */
      //IF (f.filter_type = 2, IF(v.value_id = 1, 0, 1) ,v.number)
      $response['filter_values'] = $app->db->getAll("
        SELECT
          v.value_id AS 'filter_value_id',
          IF (f.filter_type = 3, v.string, v.number) AS 'filter_value',
          IF (f.filter_type = 1, 'int', 'str')AS 'filter_type'
        FROM `values` v
        JOIN `lnk_products_values` pv ON pv.value_id = v.value_id
        JOIN `filters` f ON f.filter_id = pv.filter_id");
      // write json file
      $bd = fopen(PATH_CACHE . $file . ".json", "w");
      fwrite($bd, json_encode(array("data" => $response)));
      fclose($bd);
      // prepare response
      $response = array('response_code' => 0, 'data' => array("path" =>  "/cache/" . $file . ".json"));
      echo json_encode($response);
      $app->stop();
    }


    if ($request->signature != $SIGN)
      $response = array(
        "response_code" => 100,
        "data"          => array("error" => $app->lang->get("Signature doesn't match")),
      );
    else
      switch ($request->function) {
        /**
         * Return banner list resized towidth/height in px
         */
        case "banners":
          $banners      = $app->db->getAll("SELECT * FROM `banners` WHERE banner_active = 1 ORDER BY banner_position ASC");
          $param['far'] = 1;
          if ($request->data->width > 0) $param['w'] = $request->data->width;
          if ($request->data->height > 0) $param['h'] = $request->data->height;
          foreach ($banners as $banner) {
            $response['data']['banners'][] = array(
              "image"       => rtrim(URL_ROOT, '/') . $app->image->resize(IMAGE_STORAGE . '/banners/' . $banner['banner_image'], $param, 'banners'),
              "product_id"  => ($banner['banner_link_type'] == 1 ? $banner['banner_link_id'] : 0),
              "category_id" => ($banner['banner_link_type'] == 2 ? $banner['banner_link_id'] : 0),
            );
          }
          break;
        /**
         * Return shop list with shop names, phone, address and coordinates
         */
        case "shoplist":
          $response['data']['shoplist'] = $app->db->getAll("
            SELECT
             shop_name AS 'name',
             shop_addr AS 'address',
             shop_phone AS 'phone',
             shop_lat AS 'latitude',
             shop_lng AS 'longitude'
            FROM `shops`
            WHERE shop_active = 1
            ORDER BY shop_id ASC");
          break;
        /**
         * User registration
         */
        case "reg":
          $check = $app->db->getOne(' SELECT COUNT(*) AS "cnt" FROM `users` WHERE user_email = "' . $app->db->esc($request->data->email) . '" ');
          if ($check['cnt'] > 0) {
            $error = $app->lang->get("User with that email already registered! Try another email");
          } else {
            $password             = substr(md5(uniqid()), 10, 10);
            if ($request->data->email == 'user@example.com') $password = 'meganote';
            $request->data->index = isset($request->data->index) ? $request->data->index : '';

            $app->db->query("
              INSERT INTO `users` SET
                user_reg_date = NOW(),
                user_last_visit = NOW(),
                user_email = '" . $app->db->esc($app->db->fix($request->data->email)) . "',
                user_phone = '" . $app->db->esc($app->db->fix($request->data->phone)) . "',
                user_firstname = '" . $app->db->esc($app->db->fix($request->data->name)) . "',
                user_lastname = '" . $app->db->esc($app->db->fix($request->data->surname)) . "',
                user_pass = '" . md5($password) . "',
                user_address = '" . $app->db->esc(
                json_encode(array(
                  "index" => $app->db->fix($request->data->index),
                  "city"  => $app->db->fix($request->data->city),
                  "ave"   => $app->db->fix($request->data->street),
                  "house" => $app->db->fix($request->data->house_number),
                  "room"  => $app->db->fix($request->data->room_number),
                ))) . "' ");
            $user_id               = $app->db->getID();
            $user                  = $app->db->getOne("SELECT * FROM `users` WHERE user_id = " . $user_id);
            $user['brand']         = BRAND;
            $user['user_password'] = $password;
            if (MAIL_HOST != '')
              $app->mail->send(
                $request->data->email,
                EMAIL_SUBJECT_REG,
                EMAIL_BODY_REG,
                $user
              );

            $response['response_code']           = 0;
            $response['data']['user']['user_id'] = $user_id;
          }
          break;
        /**
         * User authentication
         */
        case "auth":
          $user = $app->db->getOne("SELECT * FROM `users` WHERE user_email = '" . $app->db->esc($request->data->email) . "'");
          if (0 == count($user))
            $error = $app->lang->get("User with that email not found! Try another email");
          elseif ($request->data->pass != $user['user_pass'])
            $error = $app->lang->get("Wrong password");
          elseif (0 == $user['user_active'])
            $error = $app->lang->get("User blocked");
          else {
            $addr     = json_decode($user['user_address'], true);
            $response = Array(
              "response_code" => 0,
              "data"          => array(
                "user" =>
                  array(
                    "user_id"      => $user['user_id'],
                    "surname"      => $user['user_lastname'],
                    "name"         => $user['user_firstname'],
                    "email"        => $user['user_email'],
                    "phone"        => $user['user_phone'],
                    "index"        => $addr['index'],
                    "city"         => $addr['city'],
                    "street"       => $addr['ave'],
                    "house_number" => $addr['house'],
                    "room_number"  => $addr['room'],
                  )));
          }
          break;
        /**
         * User password recovery
         */
        case "forgot":
          $password = substr(md5(uniqid()), 10, 10);
          $app->db->query("UPDATE `users` SET user_pass = '" . md5($password) . "' WHERE user_email = '" . $app->db->esc($request->data->email) . "'");
          if ($app->db->getAffectedRows() > 0) {
            $user                  = $app->db->getOne("SELECT * FROM `users` WHERE user_email = '" . $app->db->esc($request->data->email) . "'");
            $user['brand']         = BRAND;
            $user['user_password'] = $password;
            if (MAIL_HOST != '')
              $app->mail->send(
                $user['user_email'],
                EMAIL_SUBJECT_RECOVERY,
                EMAIL_BODY_RECOVERY,
                $user
              );
            $response = array("response_code" => 0);
          } else
            $error = $app->lang->get("Email not registered! Check it");
          break;
        /**
         * Change user password
         */

        case "change_password":
          $user = $app->db->getOne("SELECT * FROM `users` WHERE user_id = ".(int)$request->data->user_id);
          if ($user['user_id'] == "")
            $error = $app->lang->get("User with this ID doesn't exist");
          elseif ($request->data->old_password == $user['user_pass']) {
            $app->db->query("update `users` set user_pass = '".$app->db->esc($request->data->new_password)."' WHERE user_id = ".$user['user_id']);
            $response = array("response_code" => 0);
          } else
            $error = $app->lang->get("Invalid password");
          break;
        /**
         * User profile update
         */
        case "update_user":
          $check_email = $app->db->getOne('
            SELECT
              COUNT(*) AS "cnt"
            FROM `users`
            WHERE user_email = "' . $app->db->esc($request->data->email) . '"
            AND user_id != "' . $app->db->esc($request->data->user_id) . '"');

          if ($check_email['cnt'] > 0) {
            $error = $app->lang->get("User with that email already registered");
          } else {
            $addr = json_encode(Array(
              "city"  => $app->db->fix($request->data->city),
              "ave"   => $app->db->fix($request->data->street),
              "house" => $app->db->fix($request->data->house_number),
              "room"  => $app->db->fix($request->data->room_number),
              "index" => $app->db->fix($request->data->index),
            ));
            $app->db->query("UPDATE `users` SET
              user_firstname = '" . $app->db->esc($app->db->fix($request->data->name)) . "',
              user_lastname  = '" . $app->db->esc($app->db->fix($request->data->surname)) . "',
              user_phone     = '" . $app->db->esc($app->db->fix($request->data->phone)) . "',
              user_email     = '" . $app->db->esc($app->db->fix($request->data->email)) . "',
              user_address   = '" . $app->db->esc($addr) . "'
              WHERE user_id  = '" . $app->db->esc($request->data->user_id) . "'");
            $response = array("response_code" => 0);
          }
          break;
        /**
         * Get product info deprecated
         */
        case "product_info":
          $products = array();
          foreach ($request->data->products as $value)
            $products[] = (int)$value['id'];
          echo "
            SELECT
              product_id AS 'id',
              1 AS 'stock_quantity',
              product_price AS 'price'
            FROM `products`
            WHERE product_id IN (" . implode(",", $products) . ")
          ";
          $response['response_code']           = 0;
          $response['data']['actual_products'] = $app->db->getAll("
            SELECT
              product_id AS 'id',
              1 AS 'stock_quantity',
              product_price AS 'price'
            FROM `products`
            WHERE product_id IN (" . implode(",", $products) . ")
          ");
          break;
        /**
         * Create new order
         */
        case "order":
          // get user
          $order = $app->db->getOne("SELECT * FROM `users` WHERE user_id = " . $request->data->user_id);
          // get products id
          $ids = Array();
          foreach ($request->data->products as $key => $value)
            $ids[$value->id] = $value->count;
          $keys  = array_keys($ids);
          $check = $app->db->getOne("SELECT MIN(product_visible) AS 'min' FROM `products` WHERE product_id IN (" . implode(",", $keys) . ") ");
          if ($check['min'] == 0) {
            $error = $app->lang->get('You ordered unavailable item');
          } else {
            // Generate main record of order
            $app->db->query("
              INSERT INTO `orders` SET
              order_created  = NOW(),
              order_updated  = NOW(),
              order_client   = '" . $order['user_id'] . "',
              order_delivery = '{\"type\": \"" . $request->data->delivery_type_id . "\"}',
              order_comment  = '" . $app->db->esc($app->db->fix(strip_tags($request->data->comment))) . "'
            ");
            // Get order id
            $order['order_id']    = $app->db->getID();
            $order['order_cost']  = 0;
            $order['productlist'] = '';
            // Generate order letter with product list and insert products into database
            $items = $app->db->getAll("
              SELECT *
              FROM `products`
              WHERE product_id IN (" . implode(",", $keys) . ") ");
            foreach ($items as $item) {
              $item['product_count'] = $ids[$item['product_id']];
              $item['product_cost']  = round($item['product_count'] * $item['product_price'], 2);
              $order['order_cost'] += $item['product_cost'];
              $item['product_image'] = rtrim(URL_ROOT, '/') . $app->image->resize(
                  IMAGE_STORAGE . DS . "products" . DS . $item['product_id'] . DS . $item['product_cover'],
                  array(
                    'w'   => 64,
                    'h'   => 64,
                    'far' => 1,
                  ),
                  'email/products'
                );
              $app->db->query("
                INSERT INTO `lnk_order_products` SET
                order_id      = '" . $order['order_id'] . "',
                product_id    = '" . $item['product_id'] . "',
                product_count = '" . $item['product_count'] . "',
                product_price = '" . $item['product_price'] . "'
              ");
              // parsing product list
              foreach ($item as $key => $value) {
                $item["{" . $key . "}"] = $value;
              }
              $order['productlist'] .= strtr(EMAIL_BODY_ORDER_ITEM, $item);
            }
            // get delivery name and price
            $delivery = $app->db->getOne("SELECT * FROM `delivery` WHERE delivery_id = " . $request->data->delivery_type_id);
            $order['order_cost'] += $delivery['delivery_cost'];
            $order['order_delivery'] = $delivery['delivery_name'];
            $order['brand']          = BRAND;
            $order['currency']       = CURRENCY;
            $order                   = array_merge($order, $delivery);
            $app->db->query("UPDATE `orders` SET order_cost = '" . $order['order_cost'] . "' WHERE order_id = '" . $order['order_id'] . "'");
            $managers = $app->db->getAll("SELECT manager_email FROM `managers` WHERE manager_active = 1");
            if (MAIL_HOST != ''){
              foreach ($managers as $value) {
                $app->mail->send($value['manager_email'], $app->lang->get('New order'), EMAIL_BODY_ORDER, $order);
              }
              $app->mail->send($order['user_email'], EMAIL_SUBJECT_ORDER, EMAIL_BODY_ORDER, $order);
            }
            $response['response_code'] = 0;
          }
          break;
        /**
         * User order history
         */
        case "history":
          $orders = $app->db->getAll("SELECT * FROM `orders` WHERE order_client = " . (int)$request->data->user_id);
          foreach ($orders as $order) {
            switch ($order['order_status']) {
              case 0:
                $status = $app->lang->get('Deleted');
                break;
              case 1:
                $status = $app->lang->get('New');
                break;
              case 2:
                $status = $app->lang->get('Sent');
                break;
              case 3:
                $status = $app->lang->get('Delivered');
                break;
            }
            $o             = array(
              "date"     => strtotime($order['order_created']),
              "price"    => $order['order_cost'],
              "currency" => "UAH",
              "status"   => $status,
            );
            $o['products'] = $app->db->getAll("
              SELECT
                p.product_id AS 'id',
                p.product_count AS 'count',
                (SELECT product_name FROM `products` WHERE product_id = p.product_id) AS 'name'
              FROM `lnk_order_products` p
              WHERE p.order_id = " . $order['order_id']);

            $response['data']['history_orders'][] = $o;
          }
          $response['response_code'] = 0;
          break;
      }
    // prepare response output
    if (!empty($error))
      $response = array("response_code" => 100, "data" => array("error" => $error));
    if (DEBUG_MODE) {
      file_put_contents(PATH_CACHE . 'logs' . DS .  'response.log', "SERVER SIGNATURE = ".$SIGN."\nRESPONSE\n" . json_encode($response) . "\n\n", FILE_APPEND);
      // $response['report'] = URL_ROOT . 'cache/logs/' . date("Ymd_His", $runtime) . '.log';
    }
    echo json_encode($response);
    $app->stop();
  });
