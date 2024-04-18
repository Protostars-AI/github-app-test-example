const speakeasy = require('speakeasy');
const axios = require('axios');

// Dummy database to store secret keys (for demonstration purposes)
const secretKeys = new Map();

// Maximum number of allowed password attempts
const MAX_ATTEMPTS = 10;

// Password expiration period in days (90+ days)
const PASSWORD_EXPIRATION_PERIOD = 90;

// URL of the dictionary
const DICTIONARY_URL = 'https://raw.githubusercontent.com/dwyl/english-words/master/words_dictionary.json';


exports.signup = async (req, res) => {
    const { name, email, password } = req.body;

    // Check if all fields are provided
    if (!name || !email || !password) {
        return res.status(400).json({ error: 'All fields are required' });
    }

    // Check if password meets minimum length requirement
    if (password.length < 8) {
        return res.status(400).json({ error: 'Password must be at least 8 characters long' });
    }

    // Fetch the dictionary
    try {
        const response = await axios.get(DICTIONARY_URL);
        const dictionary = response.data;

        // Check if the password contains dictionary words with combinations
        const words = Object.keys(dictionary);
        if (words.some(word => newPassword.includes(word))) {
            return res.status(400).json({ error: 'Password contains dictionary words with combinations' });
        }

        // Here you can implement the logic to store the user in your database
        // For demonstration purposes, I'm just sending a success message
        res.send('Signup successful!');
    } catch (error) {
        console.error('Error fetching dictionary:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
};
  
exports.signin = (req, res) => {
  
  const { email, password } = req.body;

  // Check if email and password are provided
  if (!email || !password) {
    return res.status(400).json({ error: 'Email and password are required' });
  }

  // Here you would typically check the provided email and password against your database
  // For demonstration purposes, I'm assuming authentication is successful
  const userId = '123'; // This would be the user's ID fetched from the database

  // Get user data from the database
  let userData = usersData.get(userId);

  // If user data doesn't exist, initialize it
  if (!userData) {
    userData = { attempts: 0 };
    usersData.set(userId, userData);
  }

  // Increment the number of login attempts
  userData.attempts++;

  // Check if the maximum number of attempts exceeded
  if (userData.attempts > MAX_ATTEMPTS) {
    return res.status(401).json({ error: 'Maximum login attempts exceeded. Please try again later.' });
  }

  // Here you would typically check the provided email and password against your database
  // For demonstration purposes, I'm assuming authentication is successful
  // Additionally, assuming 2FA is successful for demonstration purposes
  // You need to implement your own authentication logic here

  // Reset the number of login attempts on successful login
  userData.attempts = 0;

  // Generate a secret key for 2FA (for demonstration purposes)
  const secret = speakeasy.generateSecret({ length: 20 });
  usersData.set(userId, { ...userData, secret: secret.base32 });

  // Send response with user ID and secret key
  res.json({ userId, secret: secret.ascii });

};

exports.verify2FA = (req, res) => {
    const { userId, token } = req.body;
  
    // Retrieve secret key for the user from the dummy database
    const secret = secretKeys.get(userId);
  
    // Verify the token
    const verified = speakeasy.totp.verify({
      secret,
      encoding: 'base32',
      token,
      window: 2, // Allow tokens to be valid for 2 time steps (30 seconds each)
    });
  
    if (verified) {
      res.json({ success: true, message: '2FA verification successful' });
    } else {
      res.status(401).json({ success: false, error: 'Invalid token' });
    }
  };

  exports.changePassword = async (req, res) => {
    const { userId, newPassword } = req.body;
  
    // Get user data from the database
    let userData = usersData.get(userId);
  
    // If user data doesn't exist, return an error
    if (!userData) {
      return res.status(404).json({ error: 'User not found' });
    }
  
    // Check if the password meets the minimum length requirement
    if (newPassword.length < 8) {
      return res.status(400).json({ error: 'Password must be at least 8 characters long' });
    }

    // Fetch the dictionary
    try {
        const response = await axios.get(DICTIONARY_URL);
        const dictionary = response.data;

        // Check if the password contains dictionary words with combinations
        const words = Object.keys(dictionary);
        if (words.some(word => newPassword.includes(word))) {
        return res.status(400).json({ error: 'Password contains dictionary words with combinations' });
        }

        // Update the last password change date for the user
        let userData = usersData.get(userId);
        if (!userData) {
        userData = {};
        }
        userData.lastPasswordChange = new Date();
        usersData.set(userId, userData);

        res.json({ success: true, message: 'Password changed successfully' });
    } catch (error) {
        console.error('Error fetching dictionary:', error);
        res.status(500).json({ error: 'Internal server error' });
    }
  };
  
  exports.checkPasswordExpiration = (req, res, next) => {
    const { userId } = req.body;
  
    // Get user data from the database
    let userData = usersData.get(userId);
  
    // If user data doesn't exist, return an error
    if (!userData) {
      return res.status(404).json({ error: 'User not found' });
    }
  
    // Check if the last password change date exists
    if (!userData.lastPasswordChange) {
      return res.status(400).json({ error: 'Last password change date not found' });
    }
  
    // Calculate the difference in days between the current date and the last password change date
    const lastPasswordChangeDate = new Date(userData.lastPasswordChange);
    const currentDate = new Date();
    const differenceInDays = Math.ceil((currentDate - lastPasswordChangeDate) / (1000 * 60 * 60 * 24));
  
    // Check if the password has expired
    if (differenceInDays > PASSWORD_EXPIRATION_PERIOD) {
      return res.status(401).json({ error: 'Password expired. Please change your password.' });
    }
  
    // Password hasn't expired, proceed to the next middleware
    next();
  };