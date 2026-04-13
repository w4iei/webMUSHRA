/*************************************************************************
         (C) Copyright AudioLabs 2017

This source code is protected by copyright law and international treaties. This source code is made available to You subject to the terms and conditions of the Software License for the webMUSHRA.js Software. Said terms and conditions have been made available to You prior to Your download of this source code. By downloading this source code You agree to be bound by the above mentionend terms and conditions, which can also be found here: https://www.audiolabs-erlangen.de/resources/webMUSHRA. Any unauthorised use of this source code may result in severe civil and criminal penalties, and will be prosecuted to the maximum extent possible under law.

**************************************************************************/

function PairedDistancePage(_pageManager, _pageTemplateRenderer, _audioContext, _bufferSize, _audioFileLoader, _session, _pageConfig, _errorHandler, _language) {
  this.pageManager = _pageManager;
  this.pageTemplateRenderer = _pageTemplateRenderer;
  this.audioContext = _audioContext;
  this.bufferSize = _bufferSize;
  this.audioFileLoader = _audioFileLoader;
  this.session = _session;
  this.pageConfig = _pageConfig;
  this.errorHandler = _errorHandler;
  this.language = _language;

  this.stimuli = [];
  for (var key in this.pageConfig.stimuli) {
    this.stimuli.push(new Stimulus(key, this.pageConfig.stimuli[key]));
  }
  this.stimuli.sort(function(a, b) {
    return parseInt(a.id, 10) - parseInt(b.id, 10);
  });

  for (var i = 0; i < this.stimuli.length; ++i) {
    this.audioFileLoader.addFile(this.stimuli[i].getFilepath(), (function(_buffer, _stimulus) {
      _stimulus.setAudioBuffer(_buffer);
    }), this.stimuli[i]);
  }

  this.genericAudioControl = null;
  this.distance = null;
  this.time = 0;
  this.startTimeOnPage = null;
  this.sequenceActive = false;
}

PairedDistancePage.prototype.getName = function() {
  return this.pageConfig.name;
};

PairedDistancePage.prototype.init = function() {
  this.genericAudioControl = new GenericAudioControl(this.audioContext, this.bufferSize, this.stimuli, this.errorHandler);
  this.genericAudioControl.addEventListener((function(_event) {
    if (_event.name === 'ended') {
      if (this.sequenceActive && _event.index === 0) {
        window.setTimeout((function() {
          if (this.sequenceActive && this.genericAudioControl !== null) {
            this.genericAudioControl.play(1);
          }
        }).bind(this), 0);
      } else {
        this.sequenceActive = false;
      }
    }
  }).bind(this));
};

PairedDistancePage.prototype.render = function(_parent) {
  var div = $("<div></div>");
  _parent.append(div);

  var content = this.pageConfig.content === null ? "" : this.pageConfig.content;
  div.append($("<p>" + content + "</p>"));

  var controls = $("<div class='default_padding'></div>");
  div.append(controls);

  controls.append($("<button data-role='button' data-inline='true' id='pairedDistancePlay1'>" + this.pageManager.getLocalizer().getFragment(this.language, 'playItem1Button') + "</button>"));
  controls.append($("<button data-role='button' data-inline='true' id='pairedDistancePlay2'>" + this.pageManager.getLocalizer().getFragment(this.language, 'playItem2Button') + "</button>"));
  controls.append($("<button data-role='button' data-inline='true' id='pairedDistancePlaySequence'>" + this.pageManager.getLocalizer().getFragment(this.language, 'playSequenceButton') + "</button>"));
  controls.append($("<button data-role='button' data-inline='true' id='pairedDistancePause'>" + this.pageManager.getLocalizer().getFragment(this.language, 'pauseButton') + "</button>"));

  $('#pairedDistancePlay1').bind("click", (function() {
    this.playStimulus(0);
  }).bind(this));
  $('#pairedDistancePlay2').bind("click", (function() {
    this.playStimulus(1);
  }).bind(this));
  $('#pairedDistancePlaySequence').bind("click", (function() {
    this.playSequence();
  }).bind(this));
  $('#pairedDistancePause').bind("click", (function() {
    this.sequenceActive = false;
    this.genericAudioControl.pause();
  }).bind(this));

  div.append($("<p><strong>" + this.pageManager.getLocalizer().getFragment(this.language, 'pairedDistanceQuestion') + "</strong></p>"));

  var scaleTable = $("<table align='center' class='default_padding'></table>");
  div.append(scaleTable);
  scaleTable.append($("<tr><td style='text-align:left; padding-right: 16px;'>" + this.pageManager.getLocalizer().getFragment(this.language, 'pairedDistanceMostSimilar') + "</td><td style='text-align:right; padding-left: 16px;'>" + this.pageManager.getLocalizer().getFragment(this.language, 'pairedDistanceMostDifferent') + "</td></tr>"));

  var radioContainer = $("<div id='paired-distance-choice' data-role='controlgroup' data-type='horizontal'></div>");
  for (var rating = 1; rating <= 7; ++rating) {
    radioContainer.append($("<input type='radio' name='paired-distance-choice' id='paired-distance-choice-" + rating + "' value='" + rating + "'>"));
    radioContainer.append($("<label for='paired-distance-choice-" + rating + "'>" + rating + "</label>"));
  }
  radioContainer.find("input[type='radio']").bind("change", (function() {
    this.pageTemplateRenderer.unlockNextButton();
  }).bind(this));
  div.append(radioContainer);

  div.append($("<p><small>" + this.pageManager.getLocalizer().getFragment(this.language, 'pairedDistanceKeyboardHelp') + "</small></p>"));
};

PairedDistancePage.prototype.playStimulus = function(_index) {
  this.sequenceActive = false;
  this.genericAudioControl.play(_index);
};

PairedDistancePage.prototype.playSequence = function() {
  this.sequenceActive = true;
  this.genericAudioControl.audioCurrentPositions[0] = 0;
  this.genericAudioControl.audioCurrentPositions[1] = 0;
  this.genericAudioControl.audioStimulusIndex = null;
  this.genericAudioControl.audioCommand = null;
  this.genericAudioControl.play(0);
};

PairedDistancePage.prototype.selectDistance = function(_value) {
  // First uncheck all radio buttons in the group
  $("input[name='paired-distance-choice']").prop('checked', false).checkboxradio('refresh');
  // Then check the selected one
  $("#paired-distance-choice-" + _value).prop('checked', true).checkboxradio('refresh').trigger('change');
};

PairedDistancePage.prototype.bindShortcuts = function() {
  Mousetrap.bind(['q'], (function() { $('#pairedDistancePlay1').click(); return false; }).bind(this));
  Mousetrap.bind(['w'], (function() { $('#pairedDistancePlay2').click(); return false; }).bind(this));
  Mousetrap.bind(['e'], (function() { $('#pairedDistancePlaySequence').click(); return false; }).bind(this));
  Mousetrap.bind(['space'], (function() { $('#pairedDistancePause').click(); return false; }).bind(this));
  for (var rating = 1; rating <= 7; ++rating) {
    (function(ratingValue, page) {
      Mousetrap.bind(String(ratingValue), function() {
        page.selectDistance(ratingValue);
        return false;
      });
    })(rating, this);
  }
  Mousetrap.bind(['enter'], (function() {
    if ($('#__button_next').length > 0 && !$('#__button_next').is(':disabled')) {
      this.pageManager.nextPage();
      return false;
    }
  }).bind(this));
};

PairedDistancePage.prototype.unbindShortcuts = function() {
  Mousetrap.unbind(['q', 'w', 'e', 'space', 'enter']);
  for (var rating = 1; rating <= 7; ++rating) {
    Mousetrap.unbind(String(rating));
  }
};

PairedDistancePage.prototype.load = function() {
  this.startTimeOnPage = new Date();
  this.genericAudioControl.initAudio();
  this.bindShortcuts();
  if (this.distance === null) {
    this.pageTemplateRenderer.lockNextButton();
  } else {
    this.selectDistance(this.distance);
  }
};

PairedDistancePage.prototype.save = function() {
  this.unbindShortcuts();
  this.sequenceActive = false;
  this.time += (new Date() - this.startTimeOnPage);
  var radio = $('#paired-distance-choice :radio:checked');
  this.distance = (radio.length > 0) ? radio[0].value : null;
  this.genericAudioControl.freeAudio();
};

PairedDistancePage.prototype.store = function() {
  var trial = this.session.getTrial(this.pageConfig.type, this.pageConfig.id);
  if (trial === null) {
    trial = new Trial();
    trial.type = this.pageConfig.type;
    trial.id = this.pageConfig.id;
    this.session.trials[this.session.trials.length] = trial;
  }

  var rating = new PairedDistanceRating();
  rating.stimulus1 = this.stimuli[0].getId();
  rating.stimulus2 = this.stimuli[1].getId();
  rating.distance = this.distance === null ? "NA" : this.distance;
  rating.time = this.time;
  trial.responses[trial.responses.length] = rating;
};
