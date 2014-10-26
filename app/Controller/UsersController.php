<?php
App::uses('AppController', 'Controller');

/**
 * Users Controller
 *
 * @property User $User
 * @property PaginatorComponent $Paginator
 */
class UsersController extends AppController {
    public $components = array('Paginator', 'RequestHandler');
    public $helpers = array('Html', 'Form', 'Js', 'Time');

    /**
     * Shows a user's profile.
     *
     * @param int $steamid the user's 64-bit steamid
     */
    public function profile($steamid) {

        $user_id = $this->AccountUtility->AccountIDFromSteamID64($steamid);

        $this->loadModel('Order');
        $this->loadModel('UserItem');
        $userItems = $this->UserItem->getByUser($user_id);

        $totals = Hash::combine($this->Order->find('all', array(
            'conditions' => array(
                'user_id' => $user_id
            ),
            'fields' => array(
                'order_detail.item_id', 'SUM(quantity) as quantity'
            ),
            'joins' => array(
                array(
                    'table' => 'order_detail',
                    'conditions' => array(
                        'Order.order_id = order_detail.order_id'
                    )
                )
            ),
            'group' => array(
                'order_detail.item_id'
            )
        )), '{n}.order_detail.item_id', '{n}.{n}.quantity' );

        $pastItems = array();

        foreach ($totals as $item_id => $quantity) {
            if (isset($userItems[$item_id])) {
                $diff = $totals[$item_id] - $userItems[$item_id];

                if ($diff > 0) {
                    $pastItems[$item_id] = $diff;
                }
            } else {
                $pastItems[$item_id] = $totals[$item_id];
            }
        }

        $user = $this->User->read('credit', $user_id);

        if (!empty($user)) {
            $this->set('credit', $user['User']['credit']);
        }

        $this->addPlayers($user_id);

        $this->set(array(
            'user_id' => $user_id,
            'userItems' => $userItems,
            'pastItems' => $pastItems,
            'totalSpent' => $this->User->getTotalSpent($user_id)
        ));

        $this->loadShoutboxData();

        $this->reviews($steamid, false);
        $this->activity($steamid, false);
    }

    /**
     * Shows a page of reviews written by a specific user. This is usually included in the profile action or called
     * via ajax directly for pagination.
     *
     * @param int $steamid the user's 64-bit steamid
     * @param bool $forceRender whether to force render. set to false if calling from another action
     */
    public function reviews($steamid, $forceRender = true) {

        $user_id = $this->AccountUtility->AccountIDFromSteamID64($steamid);

        $this->loadModel('Rating');
        $this->Paginator->settings = array(
            'Rating' => array(
                'fields'  => array(
                    'Rating.user_id', 'Rating.item_id', 'rating', 'review.review_id', 'review.created', 'review.modified', 'review.content', 'SUM(quantity) as quantity'
                ),
                'conditions' => array(
                    'Rating.user_id' => $user_id
                ),
                'joins' => array(
                    array(
                        'table' => 'review',
                        'conditions' => array(
                            'Rating.rating_id = review.rating_id'
                        )
                    ),
                    array(
                        'table' => 'order_detail',
                        'conditions' => array(
                            'Rating.item_id = order_detail.item_id'
                        )
                    ),
                    array(
                        'table' => 'order',
                        'conditions' => array(
                            'order_detail.order_id = order.order_id',
                            'Rating.user_id = order.user_id'
                        )
                    )
                ),
                'order' => 'quantity desc',
                'group' => 'order_detail.item_id',
                'limit' => 3
            )
        );

        $reviews = Hash::map(
            $this->Paginator->paginate('Rating'),
            '{n}', function ($arr){
                return array_merge(
                    $arr['Rating'],
                    $arr['review'],
                    $arr[0]
                );
            }
        );

        $this->addPlayers($user_id);
        $this->loadItems();

        $this->set(array(
            'user_id' => $user_id,
            'reviews' => $reviews,
            'displayType' => 'user',
            'reviewPageLocation' => array('controller' => 'Users', 'action' => 'reviews', 'id' => $steamid)
        ));

        if ($forceRender) {
            $this->render('/Reviews/list');
        }
    }

    /**
     * Shows activity data for a specific user. This is usually included in the profile action or called via ajax
     * directly for pagination.
     *
     * @param int $steamid the user's 64-bit steamid
     * @param bool $forceRender whether to force render. set to false if calling from another action
     */
    public function activity($steamid, $forceRender = true) {

        $user_id = $this->AccountUtility->AccountIDFromSteamID64($steamid);

        $this->loadModel('Activity');
        $this->Paginator->settings = array(
            'Activity' => array(
                'findType' => 'byUser',
                'user_id' => $user_id,
                'limit' => 5
            )
        );

        $activities = $this->Activity->getRecent($this->Paginator->paginate('Activity'));

        $this->addPlayers($activities, '{n}.{s}.user_id');
        $this->addPlayers($activities, '{n}.{s}.sender_id');
        $this->addPlayers($activities, '{n}.{s}.recipient_id');
        $this->addPlayers($activities, '{n}.RewardRecipient.{n}');

        $this->loadItems();
        $this->loadCashData();

        $this->set(array(
            'user_id' => $user_id,
            'activities' => $activities,
            'activityPageLocation' => array('controller' => 'Users', 'action' => 'activity', 'id' => $steamid)
        ));

        if ($forceRender) {
            $this->render('/Activity/list');
        }
    }

    /**
     * Allows a high-level admin to login as a specific user for debugging purposes.
     *
     * @param int $steamid the user's 64-bit steamid
     */
    public function impersonate($steamid) {
        if ($this->Access->check('Debug')) {
            $this->AccountUtility->login($steamid);
        }
        $this->redirect(array('controller' => 'Users', 'action' => 'profile', 'id' => $steamid));
    }

    /**
     * Redirects the user to Steam for authentication. This is also the return URL for when returning from Steam. If an
     * anonymous user tries to access a page that requires authentication, they will be redirected here as per the value
     * of Auth.loginAction in the components array within AppController.php.
     */
    public function login() {

        if ($this->Auth->user()) {
            $this->redirect($this->referer());
        }

        App::import('Vendor', 'Openid');

        try {

            $openid = new LightOpenID(Router::url('/', true));

            if (!$openid->mode) {

                // begin authentication
                $this->Session->write('rememberme', isset($this->request->data['rememberme']));

                $openid->identity = 'http://steamcommunity.com/openid';
                $this->Session->write('Auth.redirect', $this->referer());
                $this->redirect($openid->authUrl());

            } else if ($openid->mode == 'cancel') {

                // user cancelled authentication
                // redirect at end of function

            } else if ($openid->validate()) {

                // authentication successful
                $oid = $openid->identity;
                $steamid = substr($oid, strrpos($oid, "/") + 1);
                $loginFlags = ($this->Session->read('rememberme')) ? AccountUtilityComponent::LOGIN_SAVE : 0;
                $this->AccountUtility->login($steamid, $loginFlags);
            }
        } catch (ErrorException $e) {
            echo 'auth error: ' . $e->getMessage();
        }

        $this->redirect($this->Auth->redirectUrl());
    }

    /**
     * Logs the user out and redirects them to the default logout page as specified by Auth.logoutRedirect in the
     * components array within AppController.php.
     */
    public function logout() {
        $this->Cookie->delete('saved_login');
        $this->redirect($this->Auth->logout());
        return;
    }
}
