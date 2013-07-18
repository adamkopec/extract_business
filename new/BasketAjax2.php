<?php

class ServiceContext {
    public $id, $parent, $mini;
}

interface BasketServiceObserver {
    public function notifyServiceAboutToBeAdded(ServiceContext $context);
    public function notifyServiceAboutToBeRemoved(ServiceContext $context);
    public function notifyServiceRemoved(ServiceContext $context);
}

class EksObserver implements BasketServiceObserver
{
    public function notifyServiceAboutToBeAdded(ServiceContext $context)
    {
        $this->_clearEks();
    }

    public function notifyServiceAboutToBeRemoved(ServiceContext $context)
    {
        Company::log(...);
    }

    public function notifyServiceRemoved(ServiceContext $context)
    {
        if (!empty($context->parent)) {
            $this->_clearEks();
        }
    }

    protected function _clearEks()
    {
        basket_Model_Default::clearBasketEksData();
    }
}

class Basket_AjaxController extends Zend_Controller_Action
{
    /** @var BasketServiceObserver[] */
    protected $observers;

    public function attachObserver(BasketServiceObserver $observer) {}
    private function __notify($eventName) {
        foreach($this->observers as $observer) {
            $methodName = 'notify' . ucfirst($eventName);
            call_user_func_array(array($observer,$methodName),array_slice(func_get_args(),1));
        }
    }


    public function addinstanceAction() {
        // INSTANCE ID
        $productInstanceId = $this->_getParam('id');
        $productInstanceIds = explode(',', $productInstanceId);
        array_walk($productInstanceIds, 'intval');

        $quantity = (int) $this->_getParam('quantity');
        $parentInstance = (int) $this->_getParam('parent'); // rodzic uslugi
        $fromMiniSegment = (int) $this->_getParam('mini'); // rodzic uslugi

        $basketModel = new basket_Model_Default();
        $results = array();
        foreach($productInstanceIds as $instanceId) {
            $results[] = $basketModel->addProductInstance($instanceId, null, $quantity, $parentInstance, $fromMiniSegment);
        }

        array_walk($results, array($this, '_handleBasketAddResult'));
//        $this->_handleBasketAddResult($results);

        # pobranie dodanego bpr_id - tylko dla dodanych uslug
        $bpr_id = 0;
        if($parentInstance){
            $rivType = basket_Model_Default::R_FACTOR;
            $personId = ModelUser::getCurrentPersonId();
            $basketRecord = EisBasket::getProductBasket($productInstanceId, $personId, $rivType);
            $oEisBaskPrd = new EisBasketProduct;
            $rowInstBask = $oEisBaskPrd->getInstanceByBasketId($basketRecord->bsk_id, $productInstanceId, $rivType);
            $bpr_id = ($rowInstBask instanceof EisBasketProduct) ? $rowInstBask->bpr_id : 0;

            $this->__notify('serviceAboutToBeAdded', new ServiceContext($productInstanceId, $parentInstance, $fromMiniSegment));
        }

        // GET RESPONSE MSG
        $response = $this->getHelper('Messenger')->getAllMessages();
        $response['prd'] = $productInstanceId;
        $response['bpr_id'] = $bpr_id;
        $response['quantity'] = $quantity;
        $response['numberResult'] = $results;
        $this->getHelper('Json')->direct($response);
    }

    public function prddelAction() {
        $json = $this->getHelper('json');
        $response = array(
            'status' => false,
            'message' => ''
        );

        $id = $this->_getParam('id');
        if (!($id > 0)) {
            $response['message'] = $this->view->translate('incorrect_basket_prd_id');
            $json->direct($response);
        }

        $conn = Doctrine_Manager::connection();
        $conn->beginTransaction();

        try {
            if (!EisBasketProduct::checkOwner($id)) {
                throw new Exception('incorrect_basket_prd_id');
            }

            $basketRecord = Doctrine::getTable('EisBasketProduct')->find($id);
            if (!$basketRecord instanceof EisBasketProduct) {
                throw new Exception('product_its_not_in_the_basket');
            }

            $idBasket = $basketRecord->bpr_bsk_id;

            /*********************************************************/

            $serviceContext = ServiceContext::fromBasketProduct($basketRecord);
            $this->__notify('serviceAboutToBeRemoved', $serviceContext);

            /*********************************************************/

            # usuniecie przypisanych uslug
            EisBasketProduct::deleteProductServices($idBasket,$basketRecord->bpr_product_instance_id);

            $basketRecord->delete();

            $basketModel = new basket_Model_Default();
            $listProducts = $basketModel->getBasketProducts($idBasket);

            $oEisBas = new EisBasket();
            $rowBasket = $oEisBas->getTable()->find($idBasket);
            if (is_object($rowBasket) && count($listProducts) == 0) {
                // nie ma produktow, uwuamy koszyk
                $rowBasket->delete();
                // ustawianie cookie mowiacego o tym, ze koszyk jest pusty
                basket_Model_Default::clearCookieFlag();
            }

            /*********************************************************/

            $this->__notify('serviceRemoved', $serviceContext);

            /*********************************************************/

            $conn->commit();

            $response['status'] = true;
            $response['message'] = $this->view->translate('product_was_removed');
        } catch (Exception $e) {
//            Empathy_Log::exception($e);
            $response['message'] = $this->view->translate($e->getMessage());
        }

        $json->direct($response);
    }


}