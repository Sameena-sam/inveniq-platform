// Creates the RQ4 client questionnaire as a Google Form in your own Google account.
// Paste into script.google.com, click Run, then copy the two links from the Execution log.
function createClientForm() {
  var form = FormApp.create('Growth decisions questionnaire');
  form.setTitle('How growth decisions are made')
    .setDescription('This questionnaire is for a master\'s thesis by Sameena Siddiqua, Asia Pacific University, '
      + 'supervised by Assoc. Prof. Dr. Nirase Fathima Abubacker.\n\n'
      + 'It asks how your company decides which territories and product categories to grow. '
      + 'It takes about 15 minutes and should be filled in by whoever makes these decisions. '
      + 'Short answers are fine, and "don\'t know" is a useful answer. Your company will not be named in the thesis.')
    .setProgressBar(true)
    .setConfirmationMessage('Thank you. Your answers have been sent to Sameena.');

  // About you
  form.addTextItem().setTitle('Your role in the company').setRequired(true);
  form.addTextItem().setTitle('Your name (optional)')
    .setHelpText('Only if you are happy to be contacted about your answers.');
  form.addMultipleChoiceItem().setTitle('May your decision process be described in the thesis in anonymised form?')
    .setChoiceValues(['Yes', 'Yes, but please check the wording with me first', 'No'])
    .setRequired(true);

  // Plans at the end of FY2023-24
  form.addPageBreakItem().setTitle('Your plans at the end of FY2023-24 (around March 2024)')
    .setHelpText('Please answer with what was planned at the time, not what later turned out to work.');
  form.addParagraphTextItem().setTitle('Which territories and product categories did you plan to push for FY2024-25 onward?')
    .setHelpText('Please name each territory with its category, and list as many as you can.');
  form.addParagraphTextItem().setTitle('Which territories and product categories did you plan to cut back?');
  form.addMultipleChoiceItem().setTitle('Are your answers on this page based on records or on memory?')
    .setChoiceValues(['Mostly records from that time', 'A mix of both', 'Mostly memory']);
  form.addMultipleChoiceItem().setTitle('Do you have records from that time, such as sales plans, targets, budgets or meeting notes?')
    .setHelpText('If you can share them, Dr. Nirase will arrange how.')
    .setChoiceValues(['Yes, and I can share them', 'Yes, but I cannot share them', 'No']);

  // How decisions are made
  form.addPageBreakItem().setTitle('How these decisions are made');
  form.addParagraphTextItem().setTitle('The last time you pushed a category harder in a territory, how did you choose it?')
    .setRequired(true);
  form.addCheckboxItem().setTitle('What information did you look at?')
    .setChoiceValues(['Sales reports', 'Profit margins', 'Feedback from sales reps', 'Customer requests',
      'Competitor activity', 'Personal experience of the market'])
    .showOtherOption(true);
  form.addTextItem().setTitle('Who makes the final decision?').setHelpText('A role is enough, for example owner or sales head.');
  form.addMultipleChoiceItem().setTitle('Is the process the same every year?')
    .setChoiceValues(['Yes, about the same every year', 'It depends on the person or situation']);

  // What happened afterwards
  form.addPageBreakItem().setTitle('What happened in FY2024-25 and FY2025-26');
  form.addParagraphTextItem()
    .setTitle('Did you run any promotions, change sales reps or stop supplying any product categories in particular territories? If so, which ones?');
  form.addGridItem().setTitle('Why did these territories stop selling?')
    .setRows(['Chennai', 'Tirunelveli', 'Tuticorin', 'Dindigul', 'Nagercoil', 'Rajapalayam'])
    .setColumns(['We decided to stop', 'Lost the customers', 'Lost the sales rep', 'Another reason', 'Don\'t know']);
  form.addParagraphTextItem().setTitle('Anything you would like to add about those territories?');
  form.addParagraphTextItem().setTitle('What caused the overall decline in sales?');

  if (form.setPublished) form.setPublished(true);   // newer Google accounts create forms unpublished
  Logger.log('SEND THIS LINK: ' + form.getPublishedUrl());
  Logger.log('YOUR EDIT AND RESPONSES LINK (keep private): ' + form.getEditUrl());
}
