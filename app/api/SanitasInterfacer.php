<?php

class SanitasInterfacer implements InterfacerInterface{

    public function retrieve($labRequest)
    {
        //In sanitas case request are sent to us thus not much to do here
        //validate input
        //Check if json
        $this->process($labRequest);
    }

    /**
    * Process Sends results back to the originating system
    *
    * @param testId the id of the test to send
    * @param status either in testing stage or verification stage or edit
    */
    public function send($testId)
    {
        //if($comments==null or $comments==''){$comments = 'No Comments';

        //We use curl to send the requests
        $httpCurl = curl_init(Config::get('kblis.sanitas-url'));
        curl_setopt($httpCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($httpCurl, CURLOPT_POST, true);

        //Get the test and results 
        $test = Test::find($testId);
        $testResults = $test->testResults;

        //Measures
        $testTypeId = $test->testType()->get()->lists('id')[0];
        $testType = TestType::find($testTypeId);
        $testMeasures = $testType->measures;

        //Get external requests and all its children
        $externalDump = new ExternalDump();
        $externRequest = ExternalDump::where('test_id', '=', $testId)->get();
        $labNo = $externRequest->lists('labNo')[0];
        $externlabRequestTree = $externalDump->getLabRequestAndMeasures($labNo);

        $resultForMainTest = "";
        //IF the test has no children prepend the status to the result

        if (is_null($externlabRequestTree)) {
            if($test->test_status_id == Test::COMPLETED){
                $resultForMainTest = "Done: ".$testResults->first()->result;;
            }
            elseif ($test->test_status_id == Test::VERIFIED) {
                $resultForMainTest = "Tested and verified: ".$testResults->first()->result;;
            }
        }
        //IF the test has children, prepend the status to the interpretation
        else {
            
            if($test->test_status_id == Test::COMPLETED){
                $resultForMainTest = "Done ".$test->interpretation;
            }
            elseif ($test->test_status_id == Test::VERIFIED) {
                $resultForMainTest = "Tested and verified ".$test->interpretation;
            }
        }
        $jsonResponseString = sprintf('{"labNo": "%s","requestingClinician": "%s", "result": "%s", "verifiedby": "%s", "techniciancomment": "%s"}', 
            $labNo, $test->tested_by, $resultForMainTest, $test->tested_by, $test->test_status_id);
        $this->sendRequest($httpCurl, $jsonResponseString, $labNo);

        //loop through labRequests and foreach of them get the result and put in an array
        foreach ($externlabRequestTree as $key => $externlabRequest){ 

            $mKey = array_search($externlabRequest->investigation, $testMeasures->lists('name'));
            
            if($mKey === false){
                Log::error("MEASURE NOT FOUND: Measure $externlabRequest->investigation not found in our system");
            }
            else {
                $measureId = $testMeasures->get($mKey)->id;

                $rKey = array_search($measureId, $testResults->lists('measure_id'));
                $matchingResult = $testResults->get($rKey);

                $jsonResponseString = sprintf('{"labNo": "%s","requestingClinician": "%s", "result": "%s", "verifiedby": "%s", "techniciancomment": "%s"}', 
                            $externlabRequest->labNo, $test->tested_by, $matchingResult->result, $test->tested_by, $test->test_status_id);
                $this->sendRequest($httpCurl, $jsonResponseString, $externlabRequest->labNo);
            }
        }
        curl_close($httpCurl);
    }

    private function sendRequest($httpCurl, $jsonResponse, $labNo)
    {
        //Foreach result in the array of results send to sanitas-url in config
        curl_setopt($httpCurl, CURLOPT_POSTFIELDS, "labResult=".$jsonResponse);

        $response = curl_exec($httpCurl);

        //"Test updated" is the actual response 
        //TODO: Replace true with actual expected response this is just for testing
        if($response == true)
        {
            $updatedExternalRequest = ExternalDump::where('labNo', '=', $labNo)->first();
            $updatedExternalRequest->result_returned = 1;
            $updatedExternalRequest->save();
        }
        else if($response == false)
        {
            Log::error("HTTP Error: SanitasInterfacer failed to send $jsonResponse : Error message "+ curl_error($httpCurl));
        }
    }

    /**
     * Function for processing the requests we receive from the external system
     * and putting the data into our system.
     *
     * @var array lab_requests
     */
    public function process($labRequest)
    {
        //First: Check if patient exists, if true dont save again
        $patient = Patient::where('patient_number', '=', $labRequest['patient']['id'])->first();
        
        if (empty($patient))
        {
            $patient = new Patient();
            $patient->patient_number = $labRequest['patient']['id'];
            $patient->name = $labRequest['patient']['fullName'];
            $gender = array('Male' => Patient::MALE, 'Female' => Patient::FEMALE); 
            $patient->gender = $gender[$labRequest['patient']['gender']];
            $patient->dob = $labRequest['patient']['dateOfBirth'];
            $patient->address = $labRequest['address']['address'];
            $patient->phone_number = $labRequest['address']['phoneNumber'];
            $patient->save();
        }

        //We check if the test exists in our system if not we just save the request in stagingTable
        if($labRequest['parentLabNo'] == '0')
        {
            $testTypeId = TestType::getTestTypeIdByTestName($labRequest['investigation']);
        }
        else {
            $testTypeId = null;
        }

        if(is_null($testTypeId) && $labRequest['parentLabNo'] == '0')
        {
            $this->saveToExternalDump($labRequest, ExternalDump::TEST_NOT_FOUND);
            return;
        }
        //Check if visit exists, if true dont save again
        $visit = Visit::where('visit_number', '=', $labRequest['patientVisitNumber'])->first();
        if (empty($visit))
        {
            $visit = new Visit();
            $visit->patient_id = $patient->id;
            $visitType = array('ip' => 'In-patient', 'op' => 'Out-patient');//Should be a constant
            $visit->visit_type = $visitType[$labRequest['orderStage']];
            $visit->visit_number = $labRequest['patientVisitNumber'];

            // We'll save Visit in a transaction a little bit below
        }

        $test = null;
        //Check if parentLabNO is 0 thus its the main test and not a measure
        if($labRequest['parentLabNo'] == '0')
        {
            //Check via the labno, if this is a duplicate request and we already saved the test 
            $test = Test::where('external_id', '=', $labRequest['labNo'])->first();
            if (empty($test))
            {
                //Specimen
                $specimen = new Specimen();
                $specimen->specimen_type_id = TestType::find($testTypeId)->specimenTypes->lists('id')[0];

                // We'll save the Specimen in a transaction a little bit below

                $test = new Test();
                $test->test_type_id = $testTypeId;
                $test->test_status_id = Test::NOT_RECEIVED;
                $test->created_by = User::EXTERNAL_SYSTEM_USER; //Created by external system 0
                $test->requested_by = $labRequest['requestingClinician'];
                $test->external_id = $labRequest['labNo'];

                DB::transaction(function() use ($visit, $specimen, $test) {
                    $visit->save();
                    $specimen->save();
                    $test->visit_id = $visit->id;
                    $test->specimen_id = $specimen->id;
                    $test->save();
                });

                $this->saveToExternalDump($labRequest, $test->id);
                return;
            }
        }
        $this->saveToExternalDump($labRequest, null);
    }

    /**
    * Function for saving the data to externalDump table
    * 
    * @param $labrequest the labrequest in array format
    * @param $testId the testID to save with the labRequest or 0 if we do not have the test
    *        in our systems.
    */
    public function saveToExternalDump($labRequest, $testId)
    {
        //Dumping all the received requests to stagingTable
        $dumper = ExternalDump::firstOrNew(array('labNo' => $labRequest['labNo']));
        $dumper->labNo = $labRequest['labNo'];
        $dumper->parentLabNo = $labRequest['parentLabNo'];
        $dumper->test_id = $testId;
        $dumper->requestingClinician = $labRequest['requestingClinician'];
        $dumper->investigation = $labRequest['investigation'];
        $dumper->provisional_diagnosis = '';
        $dumper->requestDate = $labRequest['requestDate'];
        $dumper->orderStage = $labRequest['orderStage'];
        $dumper->patientVisitNumber = $labRequest['patientVisitNumber'];
        $dumper->patient_id = $labRequest['patient']['id'];
        $dumper->fullName = $labRequest['patient']["fullName"];
        $dumper->dateOfBirth = $labRequest['patient']["dateOfBirth"];
        $dumper->gender = $labRequest['patient']['gender'];
        $dumper->address = $labRequest['address']["address"];
        $dumper->postalCode = '';
        $dumper->phoneNumber = $labRequest['address']["phoneNumber"];
        $dumper->city = $labRequest['address']["city"];
        $dumper->cost = $labRequest['cost'];
        $dumper->receiptNumber = $labRequest['receiptNumber'];
        $dumper->receiptType = $labRequest['receiptType'];
        $dumper->waiver_no = '';
        $dumper->system_id = "sanitas";
        $dumper->save();
    }
}