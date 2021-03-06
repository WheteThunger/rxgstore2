<?php
App::uses('AppController', 'Controller');

/**
 * PaypalOrders Controller
 *
 * @property PaypalOrder $PaypalOrder
 *
 * @property PaypalComponent $Paypal
 * @property ServerUtilityComponent $ServerUtility
 *
 * Magic Properties (for inspection):
 * @property Activity $Activity
 */
class PaypalOrdersController extends AppController {
    public $components = array('Paginator', 'RequestHandler', 'Paypal', 'ServerUtility');
    public $helpers = array('Html', 'Form', 'Js', 'Time', 'Session');

    public function beforeFilter() {
        parent::beforeFilter();
        $this->Auth->deny();
    }

    /**
     * Returns json data for total real money spent each day in a month.
     *
     * Specify the query parameter 'offset=X' to get data for X months ago.
     */
    public function dailyTotals() {

        if (!$this->Access->check('Stats', 'read')) {
            $this->autoRender = false;
            return;
        }

        $year = date('Y');
        $month = date('n');

        if (!empty($this->request->query['year'])) {
            $year = $this->request->query['year'];
        }

        if (!empty($this->request->query['month'])) {
            $month = $this->request->query['month'];
        }

        $data = $this->PaypalOrder->getDailySums($year, $month);

        $this->set(array(
            'data' => $data,
            '_serialize' => array('data')
        ));
    }

    /**
     * Returns json data for total real money spent each month.
     */
    public function monthlyTotals() {

        if (!$this->Access->check('Stats', 'read')) {
            $this->autoRender = false;
            return;
        }

        $year = date('Y');

        if (!empty($this->request->query['year'])) {
            $year = $this->request->query['year'];
        }

        $data = $this->PaypalOrder->getMonthlySums($year);

        $this->set(array(
            'data' => $data,
            '_serialize' => array('data')
        ));
    }

    public function yearlyTotals() {

        if (!$this->Access->check('Stats', 'read')) {
            $this->autoRender = false;
            return;
        }

        $data = $this->PaypalOrder->getYearlySums();

        $this->set(array(
            'data' => $data,
            '_serialize' => array('data')
        ));
    }

    /**
     * Add Funds page (primary hub for PayPal)
     */
    public function addfunds() {

        $config = Configure::read('Store');
        $options = $config['Paypal']['Options'];

        $this->set(array(
            'options' => $options,
            'currencyMult' => $config['CurrencyMultiplier'],
            'minPrice' => max(array_keys($options)) / 100,
            'maxMult' => max($options)
        ));

        $topBuyers = $this->PaypalOrder->getTopBuyers();
        $this->addPlayers(array_keys($topBuyers));
        $this->set('topBuyers', $topBuyers);

        $this->loadShoutbox();
        $this->activity(false);
    }

    /**
     * Activity page, usually included in the addfunds page at the bottom and called via ajax for paging.
     *
     * @param bool $forceRender whether to force render. set to false if calling from another action
     */
    public function activity($forceRender = true) {

        $this->Paginator->settings = array(
            'PaypalOrder' => array(
                'limit' => 5,
            )
        );

        $paypalOrders = $this->Paginator->paginate('PaypalOrder');

        $this->addPlayers($paypalOrders, '{n}.PaypalOrder.user_id');

        $this->loadCashData();

        $this->set(array(
            'pageModel' => 'PaypalOrder',
            'activities' => $paypalOrders,
            'activityPageLocation' => array('controller' => 'PaypalOrders', 'action' => 'activity')
        ));

        if ($forceRender) {
            $this->set(array(
                'standalone' => true,
                'title' => 'Recent CASH Purchases'
            ));
            $this->render('/Activity/list');
        }
    }

    /**
     * Begins a transaction by creating the payment and sending the user to PayPal.
     */
    public function begin() {

        $this->request->allowMethod('post');

        if (empty($this->request->data['PaypalOrder'])) {
            $this->Flash->set('You did not specify an amount.', ['params' => ['class' => 'error']]);
            $this->redirect(array('controller' => 'PaypalOrders', 'action' => 'addfunds'));
            return;
        }

        $data = $this->request->data['PaypalOrder'];
        $config = Configure::read('Store');
        $options = $config['Paypal']['Options'];
        $optAmounts = array_values($options);
        $optPrices = array_keys($options);

        if (isset($data['option'])) {

            $option = $data['option'];
            $price = $optPrices[$option];
            $amount = $price * $optAmounts[$option];

        } else {

            $price = $data['amount'] * 100;

            if ($price < max($optPrices)) {
                $this->Flash->set('An error occurred.', ['params' => ['class' => 'error']]);
                $this->redirect(array('controller' => 'PaypalOrders', 'action' => 'addfunds'));
                return;
            }

            $amount = ceil($price * max($optAmounts));
        }

        $amount *= $config['CurrencyMultiplier'];
        $challenge = mt_rand();

        try {

            $payment = $this->Paypal->createPayment(
                Router::url(array('controller' => 'PaypalOrders', 'action' => 'confirm', '?' => array('challenge' => $challenge)), true),
                Router::url(array('controller' => 'PaypalOrders', 'action' => 'cancel'), true),
                $price
            );

            $approvalUrl = $this->Paypal->findApprovalUrl($payment);

            if (empty($approvalUrl)) {
                throw new Exception('Paypal Error');
            }

            $this->Session->write('buycash', array(
                'challenge' => $challenge,
                'amount' => $amount,
                'price' => $price,
                'account' => $this->Auth->user('user_id'),
                'payment' => $payment
            ));

            $this->redirect($approvalUrl);

        } catch (Exception $e) {

            $this->Flash->set('Oops! An error occurred. You have NOT been charged.', ['params' => ['class' => 'error']]);
            $this->redirect(array('action' => 'addfunds'));
        }
    }

    /**
     * The user is sent back here after confirming the purchase (instead of cancelling).
     *
     * @broadcast amount purchased
     */
    public function confirm() {

        $data = $this->Session->read('buycash');
        $query = $this->request->query;

        $steamid = $this->AccountUtility->SteamID64FromAccountID($this->Auth->user('user_id'));

        // check for bad data
        $problemWithRequest = false;

        if (empty($data)) {
            CakeLog::write('paypal_error', "$steamid attempted to confirm without any data.");
            $problemWithRequest = true;
        } else if (empty($query['challenge'])) {
            CakeLog::write('paypal_error', "$steamid attempted to confirm without a challenge token in the URL.");
            $problemWithRequest = true;
        } else if ($query['challenge'] != $data['challenge']) {
            CakeLog::write('paypal_error', "$steamid attempted to confirm with an incorrect challenge token ({$query['challenge']} != {$data['challenge']}).");
            $problemWithRequest = true;
        } else if (empty($query['PayerID'])) {
            CakeLog::write('paypal_error', "$steamid attempted to confirm without a PayerID.");
            $problemWithRequest = true;
        }

        if ($problemWithRequest) {
            $this->Flash->set('Oops! An error occurred. You have NOT been charged.', ['params' => ['class' => 'error']]);
            $this->redirect(array('action' => 'addfunds'));
            return;
        }

        $response = $this->Paypal->executePayment($data['payment'], $this->request->query['PayerID']);

        if ($response->state == 'approved') {

            $user_id = $this->Auth->user('user_id');

            $this->loadModel('User');
            $this->User->query('LOCK TABLES user WRITE');
            $this->User->id = $user_id;
            $this->User->saveField('credit', (int)$this->User->field('credit') + $data['amount']);
            $this->User->query('UNLOCK TABLES');

            $this->loadModel('Activity');

            $this->PaypalOrder->save(array(
                'paypal_order_id' => $this->Activity->getNewId('PaypalOrder'),
                'user_id' => $this->Auth->user('user_id'),
                'ppsaleid' => $response->transactions[0]->related_resources[0]->sale->id,
                'amount' => $data['price'],
                'fee' => isset($data['payment']->transactions->amount->details->fee) ? $data['payment']->transactions->amount->details->fee : 0,
                'credit' => $data['amount']
            ));

            // broadcast
            $server = $this->User->getCurrentServer($user_id);

            if ($server) {
                $this->ServerUtility->broadcastPurchaseCash($server, $user_id, $data['amount']);
            }

            $this->Flash->set('The CASH has been added to your account.', ['params' => ['class' => 'success']]);
        }

        $this->redirect(array('controller' => 'PaypalOrders', 'action' => 'addfunds'));
    }

    /**
     * The user is sent here after cancelling the transaction (instead of confirming).
     */
    public function cancel() {

        $steamid = $this->AccountUtility->SteamID64FromAccountID($this->Auth->user('user_id'));
        CakeLog::write('paypal', "$steamid cancelled a transaction.");

        $this->Flash->set('Your transaction was cancelled and you were NOT charged.', ['params' => ['class' => 'error']]);
        $this->redirect(array('controller' => 'PaypalOrders', 'action' => 'addfunds'));
    }
}
