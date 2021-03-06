<?php
App::uses('AppController', 'Controller');

/**
 * Orders Controller
 *
 * @property Order $Order
 * @property ServerUtilityComponent $ServerUtility
 *
 * Magic Properties (for inspection):
 * @property Activity $Activity
 * @property Stock $Stock
 * @property UserItem $UserItem
 */
class OrdersController extends AppController {
    public $components = array('Paginator', 'RequestHandler', 'ServerUtility');
    public $helpers = array('Html', 'Form', 'Session', 'Js', 'Time');

    public function beforeFilter() {
        parent::beforeFilter();
        $this->Auth->allow();
        $this->Auth->deny('receipt', 'checkout', 'buy');
    }

    /**
     * Returns json data for overall amount spent for each item.
     *
     * @param int $since how far back to get data
     */
    public function totals_spent($since = 0) {

        $this->set(array(
            'data' => $this->Order->OrderDetail->getTotalsSpent($since),
            '_serialize' => array('data')
        ));
    }

    /**
     * Returns json data for overall amount of each item bought.
     *
     * @param int $since how far back to get data
     */
    public function totals_bought($since = 0) {

        $this->set(array(
            'data' => $this->Order->OrderDetail->getTotalsBought($since),
            '_serialize' => array('data')
        ));
    }

    /**
     * Shows a receipt for a past order.
     *
     * @param int $order_id
     */
    public function receipt($order_id) {

        $this->loadModel('Item');
        $this->loadModel('Order');

        $order = $this->Order->find('first', array(
            'conditions' => array(
                'order_id' => $order_id,
            ),
            'contain' => array(
                'OrderDetail' => array(
                    'fields' => array('item_id', 'quantity', 'price')
                )
            )
        ));

        $user_id = $order['Order']['user_id'];

        if ($user_id != $this->Auth->user('user_id')) {
            $this->Flash->set('You do not have permission to view this receipt.', ['params' => ['class' => 'error']]);
            return;
        }

        $steamid = $this->AccountUtility->SteamID64FromAccountID($user_id);

        $this->loadItems();

        $this->set(array(
            'data' => $order,
            'steamid' => $steamid
        ));
    }

    /**
     * Completes a purchase and shows a receipt. The order data should be set in the session by a checkout process
     * before this is called.
     *
     * @broadcast order contents
     */
    public function buy() {

        $this->request->allowMethod('post');

        $order = $this->Session->read('order');

        if (empty($order)) {
            $this->Flash->set('Oops! Your cart appears to be empty.', ['params' => ['class' => 'error']]);
            return;
        }

        $user_id = $this->Auth->user('user_id');

        $this->loadModel('Item');
        $this->loadModel('Stock');
        $this->loadModel('User');
        $this->loadModel('UserItem');

        $items = $this->loadItems();

        $this->Stock->query('LOCK TABLES stock WRITE, user WRITE, user_item WRITE, user_item as UserItem WRITE');

        $stock = Hash::combine(
            $this->Stock->find('all'),
            '{n}.Stock.item_id', '{n}.Stock'
        );

        $userItem = Hash::combine(
            $this->UserItem->findAllByUserId($user_id),
            '{n}.UserItem.item_id', '{n}.UserItem'
        );

        $total = $order['total'];
        $order = $order['models'];

        foreach ($order['OrderDetail'] as $item) {

            $item_id = $item['item_id'];
            $quantity = $item['quantity'];

            if ($stock[$item_id]['quantity'] < $quantity) {

                $this->Flash->set("Oops! There are no longer sufficient {$items[$item_id]['plural']} in stock to complete your purchase.", ['params' => ['class' => 'error']]);
                $this->Session->delete('order');

                $this->Stock->query('UNLOCK TABLES');

                return;
            }

            if (isset($userItem[$item_id])) {
                $userItem[$item_id]['quantity'] += $quantity;
            } else {
                $userItem[] = array(
                    'user_id' => $user_id,
                    'item_id' => $item_id,
                    'quantity' => $quantity
                );
            }

            $stock[$item_id]['quantity'] -= $quantity;
        }

        $credit = $this->User->read('credit', $user_id)['User']['credit'];

        if ($total > $credit) {
            $this->Flash->set('You no longer have sufficient CASH to complete this purchase!', ['params' => ['class' => 'error']]);
            return;
        }

        //Commit
        $this->loadModel('Activity');

        $this->Stock->saveMany($stock, array('atomic' => false));
        $this->UserItem->saveMany($userItem, array('atomic' => false));
        $this->User->saveField('credit', $credit - $total);
        $this->Stock->query('UNLOCK TABLES');

        $order['Order']['order_id'] = $this->Activity->getNewId('Order');

        $this->Order->saveAssociated($order, array('atomic' => false));
        $this->Session->delete('order');
        $this->Session->delete('cart');

        //Broadcast to server if player is in-game
        $server = $this->User->getCurrentServer($user_id);

        if ($server) {
            $this->ServerUtility->broadcastPurchase($server, $user_id, $order);
        }

        $this->set('order', $order);
        $this->set('steamid', $this->Auth->user('steamid'));

        $this->Flash->set('Your purchase is complete. Here is your receipt.', ['params' => ['class' => 'success']]);
    }
}
