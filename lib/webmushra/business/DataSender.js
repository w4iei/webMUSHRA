/*************************************************************************
         (C) Copyright AudioLabs 2017

This source code is protected by copyright law and international treaties. This source code is made available to You subject to the terms and conditions of the Software License for the webMUSHRA.js Software. Said terms and conditions have been made available to You prior to Your download of this source code. By downloading this source code You agree to be bound by the above mentionend terms and conditions, which can also be found here: https://www.audiolabs-erlangen.de/resources/webMUSHRA. Any unauthorised use of this source code may result in severe civil and criminal penalties, and will be prosecuted to the maximum extent possible under law.

**************************************************************************/

function DataSender(config) {
  this.target = config.remoteService;
}

DataSender.prototype.send = function(_session) {
  var sessionJSON = JSON.stringify(_session);
  var httpReq = new XMLHttpRequest();
  var params = "sessionJSON=" + encodeURIComponent(sessionJSON);
  var response = null;
  var responseText = "";
  try {
    httpReq.open("POST", this.target, false);  // synchron
    httpReq.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    httpReq.send(params);
  }
  catch (e) {
    console.log(httpReq.responseText);
    return "Could not reach the result server.";
  }

  responseText = httpReq.responseText || "";
  if (responseText !== "") {
    try {
      response = JSON.parse(responseText);
    }
    catch (e) {
      response = null;
    }
  }

  if (httpReq.status != 200) {
    console.log(responseText);
    if (response && response.message) {
      return response.message;
    }
    if (responseText !== "") {
      return responseText;
    }

    return "The result server returned HTTP status " + httpReq.status + ".";
  }

  if (response && response.status === "error") {
    console.log(responseText);
    return response.message || "The result server did not confirm that any files were written.";
  }

  return null;
};
