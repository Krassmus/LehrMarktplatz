<?php

require_once 'app/controllers/plugin_controller.php';

/** 
* This controller tells a client we provide OAI-PMH protocol and delivers oai-lom-data to harvest.
* Gets initialized due to requests. Validates metadata-prefix and used verb
* and calls a response-template with specified values.
*/
class OaiController extends PluginController 

{
 
    public function index_action() 
    {
        $allowed_verbs = ['GetRecord', 'Identify', 'ListIdentifiers', 'ListMetadataFormats', 'ListRecords', 'ListSets'];
        $allowed_prefix = ['oai_lom-de'];
        $this->allowed_prefix = $allowed_prefix;
        $request = Request::getInstance();
        
        $verb = $request->offsetGet('verb');
        if (!empty($verb) && in_array($verb, $allowed_verbs)) {
            $verb = lcfirst($verb);
            $this->verb = $verb;   
        } else {
            $this->render_template("oai/badVerb");
        }

        $metadataPrefix = $request->offsetGet('metadataPrefix');
        if (empty($metadataPrefix) || in_array($metadataPrefix, $allowed_prefix)) {
            $this->metadataPrefix = $metadataPrefix; 
        } else {
            $this->render_template("oai/badPrefix");
        }

        if ($this->verb) {
            $this->prepareRequest($request, $verb, $metadataPrefix);
        }  
    }

    public function prepareRequest($request, $verb, $metadataPrefix)
    {
        $this->currentDate = date(DATE_ATOM, time());
        $this->from = $request->offsetGet('from');
        $set = $request->offsetGet('set');

        switch ($verb) {
            case 'getRecord':
                $this->prepareGetRecord($request);
                break;
            case 'identify':
                $this->prepareIdentifier();
                break;
            case 'listIdentifiers':
                $this->prepareListIdentifiers($set);
                break;
            case 'listMetadataFormats':
                $this->prepareListMetadataFormats($request);
                break;
            case 'listRecords': 
                $this->prepareListRecords($set);
                break;
            case 'listSets':
                $this->prepareListSets();
                break;        
        }
    } 

    public function prepareGetRecord($request) 
    {
        $identifier = $request->offsetGet('identifier');
        if($targetMaterial = LernMarktplatzMaterial::find($identifier)) {
            $this->targetMaterial = $targetMaterial;
            $this->tags = $targetMaterial->getTopics();
            $this->duration = $this->calcDuration($targetMaterial->mkdate);
            $this->renderResponse($this->verb);
        } else {
            $this->render_template("oai/idNotExists");
        }
    }

    public function prepareListRecords($set) 
    {
        $tags = [];
        if ($this->records = LernMarktplatzMaterial::findByTag($set)) {
            foreach ($this->records as $targetRecord) {
                $this->tags = $targetRecord->getTopics();
                $this->duration = $this->calcDuration($targetRecord->mkdate);
    
            }
            $this->renderResponse($this->verb);
        } else {
            $this->render_template("oai/noRecordsMatch");
        }
    }

    public function prepareIdentifier() 
    {
        if ($identifier = LernmarktplatzTag::findBySQL('1')) {
            $this->identifier = $identifier;
            $this->renderResponse($this->verb);
        } else {
            $this->render_template("oai/noSets");
        }
    }

    public function prepareListIdentifiers($set) 
    {
        if (!empty($set)) {
            $this->set = $set;
            $this->records = LernMarktplatzMaterial::findByTag($set);
            $this->renderResponse($this->verb);
        } else {
            $this->render_template("oai/noSets");
        }
    }

    public function prepareListMetadataFormats($request) 
    {
        $identifier = $request->offsetGet('identifier');
        if($targetMaterial = LernMarktplatzMaterial::find($identifier)) {
            $this->targetMaterial = $targetMaterial;
            $this->renderResponse($this->verb);
        } else {
            $this->render_template("oai/idNotExists");
        }
    }
    
    public function prepareListSets() 
    {
        if ($tags = LernmarktplatzTag::findBySQL('1')) {
            $this->tags = $tags;
            $this->renderResponse($this->verb);
        }
        $this->render_template("oai/noSets");
    }

    public function calcDuration ($mkdate) 
    {
        $mkdate = intval($mkdate);
        $dateCurrent = date('s-i-h-d-m-Y', time());
        $dateCurrent = DateTime::createFromFormat('s-i-h-d-m-Y', $dateCurrent);
        $dateCreate = date('s-i-h-d-m-Y', $mkdate);
        $dateCreate = DateTime::createFromFormat('s-i-h-d-m-Y', $dateCreate);
        $difference = date_diff($dateCurrent, $dateCreate);
        return $difference;
    }

    public function renderResponse($verb) 
    {
        $this->render_template("oai/".$verb);
    }

}
